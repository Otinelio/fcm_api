<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyCard;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Campagnes SMS/notifications du commerce, adossées à
 * `notification_campaigns`. Chaque envoi consomme le crédit SMS du
 * restaurant (`restaurants.sms_credits`).
 */
class MerchantCampaignController extends Controller
{
    /**
     * GET /api/merchant/campaigns
     */
    public function index(Request $request): JsonResponse
    {
        /** @var Restaurant $restaurant */
        $restaurant = $request->user();

        $campaigns = DB::table('notification_campaigns')
            ->where('restaurant_id', $restaurant->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($row) => $this->campaignData($row))
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
            'scheduled_at'   => ['nullable', 'date'],
        ]);

        $recipients = $this->recipientCount($restaurant, $data['recipient_type']);

        if ($recipients > $restaurant->sms_credits) {
            return response()->json([
                'message' => "Crédit SMS insuffisant : {$recipients} destinataires pour {$restaurant->sms_credits} SMS restants.",
            ], 422);
        }

        $scheduled = $data['scheduled_at'] ?? null;
        $now = now();

        $id = DB::table('notification_campaigns')->insertGetId([
            'restaurant_id' => $restaurant->id,
            'title'         => 'Campagne SMS',
            'message'       => $data['message'],
            'kind'          => 'manual',
            'target'        => json_encode([
                'recipient_type'   => $data['recipient_type'],
                'recipients_count' => $recipients,
            ]),
            'scheduled_at'  => $scheduled,
            'sent_at'       => $scheduled ? null : $now,
            'status'        => $scheduled ? 'scheduled' : 'sent',
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        // Le crédit est débité à l'envoi effectif ; une campagne programmée
        // ne le consomme qu'au moment de son exécution.
        if (! $scheduled) {
            $restaurant->decrement('sms_credits', $recipients);
        }

        $row = DB::table('notification_campaigns')->find($id);

        return response()->json([
            'message'  => $scheduled ? 'Campagne programmée.' : 'Campagne envoyée.',
            'campaign' => $this->campaignData($row),
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
            'recipients_count' => $this->recipientCount($restaurant, $type),
        ]);
    }

    private function recipientCount(Restaurant $restaurant, string $type): int
    {
        $query = LoyaltyCard::where('restaurant_id', $restaurant->id);

        if ($type === 'inactive') {
            $query->where(function ($q) {
                $q->whereNull('last_activity_at')
                    ->orWhere('last_activity_at', '<', now()->subDays(30));
            });
        } elseif ($type === 'reward_available') {
            $query->where('status', 'reward_available');
        }

        return $query->count();
    }

    private function campaignData(object $row): array
    {
        $target = json_decode($row->target ?? '{}', true) ?: [];

        return [
            'id'               => (string) $row->id,
            'message'          => $row->message,
            'recipient_type'   => $target['recipient_type'] ?? 'all',
            'recipients_count' => (int) ($target['recipients_count'] ?? 0),
            'status'           => $row->status,
            'scheduled_at'     => $row->scheduled_at,
            'sent_at'          => $row->sent_at,
            'created_at'       => $row->created_at,
        ];
    }
}
