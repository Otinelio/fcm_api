<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendCampaignNotification;
use App\Models\NotificationCampaign;
use App\Models\Restaurant;
use App\Services\Campaigns\CampaignRecipientResolver;
use App\Services\Campaigns\CampaignThrottle;
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

        $campaigns = NotificationCampaign::where('restaurant_id', $restaurant->id)
            ->withCount([
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
            'message'        => ['required', 'string', 'max:500'],
            'recipient_type' => ['required', 'string', 'max:50'],
            'client_ids'     => ['required', 'array', 'min:1'],
            'client_ids.*'   => ['integer'],
            'scheduled_at'   => ['nullable', 'date'],
        ]);

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

        if ($clientIds->isEmpty()) {
            return response()->json([
                'message' => 'Aucun destinataire valide pour ce commerce.',
            ], 422);
        }

        $recipients = $clientIds->count();
        $explicitSchedule = $data['scheduled_at'] ?? null;

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

        $campaign = NotificationCampaign::create([
            'restaurant_id' => $restaurant->id,
            'title'         => 'Campagne SMS',
            'message'       => $data['message'],
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

    private function campaignData(NotificationCampaign $campaign): array
    {
        $target = $campaign->target ?? [];

        return [
            'id'               => (string) $campaign->id,
            'message'          => $campaign->message,
            'recipient_type'   => $target['recipient_type'] ?? 'all',
            'recipients_count' => (int) ($target['recipients_count'] ?? 0),
            'status'           => $campaign->status,
            'scheduled_at'     => $campaign->scheduled_at,
            'sent_at'          => $campaign->sent_at,
            'created_at'       => $campaign->created_at,
            // Absents (pas de `withCount`) juste après `store()` : les jobs
            // d'envoi n'ont pas encore tourné, 0/0 est donc exact à cet
            // instant — seul `index()` les charge réellement via `logs()`.
            'delivered_count'  => $campaign->delivered_count ?? 0,
            'failed_count'     => $campaign->failed_count ?? 0,
        ];
    }
}
