<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendCampaignNotification;
use App\Models\NotificationCampaign;
use App\Models\Restaurant;
use App\Services\Campaigns\CampaignRecipientResolver;
use App\Services\Campaigns\CampaignThrottle;
use App\Services\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Campagnes SMS/notifications du commerce, adossées à
 * `notification_campaigns`.
 *
 * Phase de test gratuite : pas de plan/crédit payant. Seuls garde-fous —
 * voir `CampaignThrottle` — une plage d'envoi (8h-20h, un envoi demandé hors
 * plage est automatiquement reprogrammé à l'ouverture suivante plutôt que
 * rejeté) et un plafond quotidien de destinataires par commerce.
 */
class MerchantCampaignController extends Controller
{
    public function __construct(private readonly CampaignThrottle $throttle)
    {
    }

    /**
     * GET /api/merchant/campaigns
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $query = NotificationCampaign::where('restaurant_id', $restaurant->id);

        if ($request->boolean('archived')) {
            $query->whereNotNull('archived_at');
        } else {
            $query->whereNull('archived_at');
        }

        $campaigns = $query->withCount([
                'logs as delivered_count' => fn ($q) => $q->where('status', 'sent'),
                'logs as failed_count' => fn ($q) => $q->where('status', 'failed'),
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (NotificationCampaign $campaign) => $this->campaignData($campaign))
            ->all();

        return response()->json(['campaigns' => $campaigns]);
    }

    /**
     * GET /api/merchant/campaigns/{campaign}
     *
     * Détail complet d'une campagne : infos générales + liste des
     * destinataires avec statut de livraison individuel.
     */
    public function show(Request $request, NotificationCampaign $campaign): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        if ($campaign->restaurant_id !== $restaurant->id) {
            abort(404);
        }

        $campaign->loadCount([
            'logs as delivered_count' => fn ($q) => $q->where('status', 'sent'),
            'logs as failed_count' => fn ($q) => $q->where('status', 'failed'),
        ]);

        $logs = $campaign->logs()->with('client:id,first_name,last_name,phone')->get();

        $recipients = $logs->map(fn ($log) => [
            'client_id' => $log->client_id,
            'name' => trim(($log->client->first_name ?? '') . ' ' . ($log->client->last_name ?? '')),
            'phone' => $log->client->phone ?? null,
            'status' => $log->status, // 'sent' | 'failed'
            'failure_reason' => $log->failure_reason,
            'sent_at' => $log->sent_at,
        ])->values();

        // Destinataires prévus mais pas encore traités par la queue
        $loggedClientIds = $logs->pluck('client_id')->toArray();
        $targetClientIds = $campaign->target['recipient_client_ids'] ?? [];
        $pendingIds = array_diff($targetClientIds, $loggedClientIds);

        if (!empty($pendingIds)) {
            $pendingClients = \App\Models\Client::whereIn('id', $pendingIds)
                ->select('id', 'first_name', 'last_name', 'phone')
                ->get();
            foreach ($pendingClients as $client) {
                $recipients->push([
                    'client_id' => $client->id,
                    'name' => trim("$client->first_name $client->last_name"),
                    'phone' => $client->phone,
                    'status' => 'pending',
                    'failure_reason' => null,
                    'sent_at' => null,
                ]);
            }
        }

        return response()->json([
            'campaign' => [
                ...$this->campaignData($campaign),
                'recipients' => $recipients,
            ],
        ]);
    }

    /**
     * POST /api/merchant/campaigns
     *
     * Programme ou envoie une campagne. Le nombre de destinataires est
     * calculé à partir des cartes du commerce, jamais fourni par le client.
     */
    public function store(Request $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $data = $request->validate([
            'type'           => ['required', 'string', 'max:50'],
            'title'          => ['nullable', 'string', 'max:120'],
            'message'        => ['nullable', 'string', 'max:500'],
            'image_url'      => ['nullable', 'string', 'max:500'],
            'image'          => ['nullable', 'image', 'max:5120'],
            'recipient_type' => ['required', 'string', 'max:50'],
            'client_ids'     => ['required', 'array', 'min:1'],
            'client_ids.*'   => ['integer'],
            'scheduled_at'   => ['nullable', 'date'],
        ]);

        $imageUrl = $data['image_url'] ?? null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('campaigns', 'public');
            $imageUrl = config('app.url') . '/storage/' . $path;
        }

        // Les destinataires viennent de l'écran "Destinataires" (segment +
        // cases à cocher affinées à la main) : jamais dérivés du resolver
        // côté serveur seul. On les rescope quand même au commerce
        // authentifié — un id hors périmètre ne doit ni compter dans le
        // crédit débité, ni recevoir de SMS.
        $clientIds = \App\Models\LoyaltyCard::where('restaurant_id', $restaurant->id)
            ->whereIn('client_id', $data['client_ids'])
            ->pluck('client_id')
            ->unique()
            ->values();

        if ($data['type'] === 'reward') {
            $clientIds = \App\Models\LoyaltyCard::where('restaurant_id', $restaurant->id)
                ->whereIn('client_id', $clientIds)
                ->where('status', 'reward_available')
                ->pluck('client_id')
                ->unique()
                ->values();
        }

        if ($clientIds->isEmpty()) {
            return response()->json([
                'message' => 'Aucun destinataire valide pour ce commerce.',
            ], 422);
        }

        $recipients = $clientIds->count();
        $explicitSchedule = $data['scheduled_at'] ?? null;

        if ($restaurant->sms_credits < $recipients) {
            return response()->json([
                'message' => 'Crédit de notifications insuffisant. Veuillez recharger votre compte.',
            ], 422);
        }

        // Un envoi immédiat demandé hors plage (8h-20h) n'est jamais
        // rejeté : il est reprogrammé à l'ouverture suivante, comme s'il
        // avait été explicitement planifié. Une programmation explicite du
        // marchand pour une heure précise, elle, est respectée telle quelle.
        $sendNow = $explicitSchedule === null && $this->throttle->isWithinSendWindow();
        $scheduledAt = $explicitSchedule
            ?? ($this->throttle->isWithinSendWindow() ? null : $this->throttle->nextWindowStart());

        if ($sendNow && $recipients > $this->throttle->remainingToday($restaurant)) {
            $cap = CampaignThrottle::DAILY_RECIPIENT_CAP;

            return response()->json([
                'message' => "Plafond quotidien de {$cap} destinataires atteint pour aujourd'hui — réessayez demain ou programmez l'envoi.",
            ], 422);
        }

        $restaurant->decrement('sms_credits', $recipients);
        $this->checkCreditAlerts($restaurant);

        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'type'          => $data['type'],
            'title'         => $data['title'] ?? null,
            'message'       => $data['message'] ?? null,
            'image_url'     => $imageUrl,
            'kind'          => 'manual',
            'target'        => [
                'recipient_type'      => $data['recipient_type'],
                'recipients_count'    => $recipients,
                'recipient_client_ids' => $clientIds->all(),
            ],
            'scheduled_at'  => $scheduledAt,
            'sent_at'       => $sendNow ? now() : null,
            'status'        => $sendNow ? 'sent' : 'scheduled',
        ]);

        if ($sendNow) {
            foreach ($clientIds as $clientId) {
                SendCampaignNotification::dispatch($campaign->id, $clientId);
            }
        }

        return response()->json([
            'message'  => $sendNow ? 'Campagne envoyée.' : 'Campagne programmée.',
            'campaign' => $this->campaignData($campaign),
        ], 201);
    }

    /**
     * GET /api/merchant/campaigns/recipients?recipient_type=
     */
    public function recipients(Request $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $type = (string) $request->query('recipient_type', 'all');

        return response()->json([
            'recipients_count' => app(CampaignRecipientResolver::class)->resolve($restaurant, $type)->count(),
        ]);
    }

    /**
     * GET /api/merchant/campaigns/recipients-list?recipient_type=&q=&sort=
     *
     * Liste hydratée (nom/téléphone/dernière activité) des destinataires
     * d'un segment, pour la page "Destinataires" du wizard de campagne :
     * l'app affiche des cases à cocher pré-cochées sur ce résultat, que le
     * marchand peut ensuite affiner à la main.
     */
    public function recipientsList(Request $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $type = (string) $request->query('recipient_type', 'all');
        $search = trim((string) $request->query('q', ''));
        $sort = (string) $request->query('sort', 'activity');

        $clientIds = app(CampaignRecipientResolver::class)->resolve($restaurant, $type);

        $query = \App\Models\LoyaltyCard::query()
            ->with('client')
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('client_id', $clientIds);

        if ($search !== '') {
            $query->whereHas('client', function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        match ($sort) {
            'recent' => $query->orderByDesc('created_at'),
            'oldest' => $query->orderBy('created_at'),
            default => $query->orderByDesc('last_activity_at'),
        };

        $recipients = $query->get()->map(fn ($card) => [
            'client_id' => $card->client_id,
            'name' => trim("{$card->client->first_name} {$card->client->last_name}"),
            'phone' => $card->client->phone,
            'last_activity_at' => $card->last_activity_at,
            'cycles_completed' => $card->cycles_completed,
        ])->values();

        return response()->json(['recipients' => $recipients]);
    }

    /**
     * POST /api/merchant/campaigns/draft
     *
     * Sauvegarde une campagne en brouillon.
     */
    public function saveDraft(Request $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $data = $request->validate([
            'id'             => ['nullable', 'integer'],
            'type'           => ['required', 'string', 'max:50'],
            'title'          => ['nullable', 'string', 'max:120'],
            'message'        => ['nullable', 'string', 'max:500'],
            'image_url'      => ['nullable', 'string', 'max:500'],
            'image'          => ['nullable', 'image', 'max:5120'],
            'recipient_type' => ['nullable', 'string', 'max:50'],
            'client_ids'     => ['nullable', 'array'],
            'client_ids.*'   => ['integer'],
            'scheduled_at'   => ['nullable', 'date'],
            'draft_step'     => ['nullable', 'integer', 'min:1', 'max:4'],
        ]);

        $imageUrl = $data['image_url'] ?? null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('campaigns', 'public');
            $imageUrl = config('app.url') . '/storage/' . $path;
        }

        $clientIds = $data['client_ids'] ?? [];
        if (!empty($clientIds)) {
            $clientIds = \App\Models\LoyaltyCard::where('restaurant_id', $restaurant->id)
                ->whereIn('client_id', $clientIds)
                ->pluck('client_id')
                ->unique()
                ->values()
                ->all();
        }

        $campaign = null;
        if (!empty($data['id'])) {
            $campaign = NotificationCampaign::where('id', $data['id'])
                ->where('restaurant_id', $restaurant->id)
                ->first();
        }

        $payload = [
            'type' => $data['type'],
            'title' => $data['title'] ?? '',
            'message' => $data['message'] ?? '',
            'image_url' => $imageUrl,
            'kind' => 'manual',
            'target' => [
                'recipient_type' => $data['recipient_type'] ?? 'all',
                'recipients_count' => count($clientIds),
                'recipient_client_ids' => $clientIds,
                'draft_step' => $data['draft_step'] ?? 1,
            ],
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'status' => 'draft',
        ];

        if ($campaign) {
            $campaign->update($payload);
        } else {
            $payload['restaurant_id'] = $restaurant->id;
            $campaign = NotificationCampaign::create($payload);
        }

        return response()->json([
            'message' => 'Brouillon sauvegardé.',
            'campaign' => $this->campaignData($campaign->fresh()),
        ], 200);
    }

    /**
     * PUT /api/merchant/campaigns/{campaign}
     *
     * Édite une campagne encore `scheduled` (message, destinataires, date) —
     * une campagne `sent` n'est plus modifiable, les SMS sont déjà partis.
     * Aucun débit de crédit ici : il n'a lieu qu'à l'envoi effectif (voir
     * `DispatchScheduledCampaigns`), édition comprise.
     */
    public function update(Request $request, NotificationCampaign $campaign): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        if ($campaign->restaurant_id !== $restaurant->id) {
            abort(404);
        }

        if ($campaign->status !== 'scheduled' && $campaign->status !== 'draft') {
            return response()->json([
                'message' => 'Seule une campagne encore programmée ou en brouillon peut être modifiée.',
            ], 422);
        }

        $data = $request->validate([
            'type'           => ['required', 'string', 'max:50'],
            'title'          => ['nullable', 'string', 'max:120'],
            'message'        => ['nullable', 'string', 'max:500'],
            'image_url'      => ['nullable', 'string', 'max:500'],
            'image'          => ['nullable', 'image', 'max:5120'],
            'recipient_type' => ['required', 'string', 'max:50'],
            'client_ids'     => ['required', 'array', 'min:1'],
            'client_ids.*'   => ['integer'],
            'scheduled_at'   => ['nullable', 'date'],
        ]);

        $imageUrl = $data['image_url'] ?? null;
        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('campaigns', 'public');
            $imageUrl = config('app.url') . '/storage/' . $path;
        }

        $clientIds = \App\Models\LoyaltyCard::where('restaurant_id', $restaurant->id)
            ->whereIn('client_id', $data['client_ids'])
            ->pluck('client_id')
            ->unique()
            ->values();

        if ($data['type'] === 'reward') {
            $clientIds = \App\Models\LoyaltyCard::where('restaurant_id', $restaurant->id)
                ->whereIn('client_id', $clientIds)
                ->where('status', 'reward_available')
                ->pluck('client_id')
                ->unique()
                ->values();
        }

        if ($clientIds->isEmpty()) {
            return response()->json([
                'message' => 'Aucun destinataire valide pour ce commerce.',
            ], 422);
        }

        $recipients = $clientIds->count();
        $oldRecipients = $campaign->status === 'draft' ? 0 : ($campaign->target['recipients_count'] ?? 0);
        $diff = $recipients - $oldRecipients;

        if ($diff > 0 && $restaurant->sms_credits < $diff) {
            return response()->json([
                'message' => 'Crédit de notifications insuffisant pour cette audience.',
            ], 422);
        }

        $explicitSchedule = $data['scheduled_at'] ?? null;
        $sendNow = false;
        $scheduledAt = $explicitSchedule;
        $newStatus = $campaign->status;
        $sentAt = $campaign->sent_at;

        if ($campaign->status === 'draft') {
            $sendNow = $explicitSchedule === null && $this->throttle->isWithinSendWindow();
            $scheduledAt = $explicitSchedule
                ?? ($this->throttle->isWithinSendWindow() ? null : $this->throttle->nextWindowStart());
            $newStatus = $sendNow ? 'sent' : 'scheduled';
            $sentAt = $sendNow ? now() : null;
            
            if ($sendNow && $recipients > $this->throttle->remainingToday($restaurant)) {
                $cap = CampaignThrottle::DAILY_RECIPIENT_CAP;
                return response()->json([
                    'message' => "Plafond quotidien de {$cap} destinataires atteint pour aujourd'hui — réessayez demain ou programmez l'envoi.",
                ], 422);
            }
        }

        if ($diff > 0) {
            $restaurant->decrement('sms_credits', $diff);
            $this->checkCreditAlerts($restaurant);
        } elseif ($diff < 0) {
            $restaurant->increment('sms_credits', abs($diff));
        }

        $campaign->update([
            'type' => $data['type'],
            'title' => $data['title'] ?? null,
            'message' => $data['message'] ?? null,
            'image_url' => $imageUrl,
            'target' => [
                'recipient_type' => $data['recipient_type'],
                'recipients_count' => $recipients,
                'recipient_client_ids' => $clientIds->all(),
            ],
            'scheduled_at' => $scheduledAt,
            'status' => $newStatus,
            'sent_at' => $sentAt,
        ]);

        if ($sendNow) {
            foreach ($clientIds as $clientId) {
                SendCampaignNotification::dispatch($campaign->id, $clientId);
            }
        }

        return response()->json([
            'message' => 'Campagne modifiée.',
            'campaign' => $this->campaignData($campaign->fresh()),
        ]);
    }

    /**
     * POST /api/merchant/campaigns/{campaign}/archive
     *
     * Masque la campagne de l'historique (`index()`) sans la supprimer —
     * réversible en base, juste `archived_at` posé.
     */
    public function archive(Request $request, NotificationCampaign $campaign): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        if ($campaign->restaurant_id !== $restaurant->id) {
            abort(404);
        }

        if ($campaign->status === 'scheduled' && is_null($campaign->archived_at)) {
            $restaurant->increment('sms_credits', $campaign->target['recipients_count'] ?? 0);
        }

        $campaign->update(['archived_at' => now()]);

        return response()->json(['message' => 'Campagne archivée.']);
    }

    private function campaignData(NotificationCampaign $campaign): array
    {
        $target = $campaign->target ?? [];

        return [
            'id'               => (string) $campaign->id,
            'type'             => $campaign->type,
            'title'            => $campaign->title,
            'message'          => $campaign->message,
            'image_url'        => $campaign->image_url,
            'recipient_type'   => $target['recipient_type'] ?? 'all',
            'recipient_ids'    => $target['recipient_client_ids'] ?? [],
            'recipients_count' => (int) ($target['recipients_count'] ?? 0),
            'status'           => $campaign->status,
            'scheduled_at'     => $campaign->scheduled_at,
            'sent_at'          => $campaign->sent_at,
            'created_at'       => $campaign->created_at,
            'draft_step'       => (int) ($target['draft_step'] ?? 1),
            // Absents (pas de `withCount`) juste après `store()` : les jobs
            // d'envoi n'ont pas encore tourné, 0/0 est donc exact à cet
            // instant — seul `index()` les charge réellement via `logs()`.
            'delivered_count'  => $campaign->delivered_count ?? 0,
            'failed_count'     => $campaign->failed_count ?? 0,
        ];
    }

    private function checkCreditAlerts(Restaurant $restaurant): void
    {
        $restaurant->refresh();
        $credits = $restaurant->sms_credits;
        $dispatcher = app(NotificationDispatcher::class);

        if ($credits === 0) {
            $dispatcher->send(
                $restaurant,
                'merchant_sms_depleted',
                'Crédit épuisé ⚠️',
                'Votre crédit de notifications est épuisé. Rechargez pour continuer à envoyer des campagnes.',
            );
        } elseif ($credits <= 10) {
            $alreadyNotified = \App\Models\Notification::where('notifiable_type', $restaurant->getMorphClass())
                ->where('notifiable_id', $restaurant->getKey())
                ->where('type', 'merchant_sms_low')
                ->where('created_at', '>=', now()->subDay())
                ->exists();

            if (!$alreadyNotified) {
                $dispatcher->send(
                    $restaurant,
                    'merchant_sms_low',
                    'Crédit faible 📉',
                    "Il ne vous reste que {$credits} crédit(s) de notification. Pensez à recharger.",
                );
            }
        }
    }
}
