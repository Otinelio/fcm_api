<?php

namespace App\Services\Proximity;

use App\Models\Client;
use App\Models\ClientRestaurantGeoOptin;
use App\Models\LoyaltyCard;
use App\Models\Restaurant;
use App\Services\NotificationDispatcher;
use Illuminate\Support\Facades\Log;

class ProximityNotificationService
{
    /** Hystérésis de sortie en mètres pour éviter le papillonnage GPS aux frontières */
    public const HYSTERESIS_BUFFER_METERS = 50.0;

    /** Délai anti-spam fixe : 24 heures entre deux notifications pour un même établissement */
    public const COOLDOWN_HOURS = 24;

    public function __construct(
        protected NotificationDispatcher $notificationDispatcher
    ) {}

    /**
     * Traite la position GPS d'un client et évalue la proximité avec les restaurants
     * dont il possède la carte de fidélité.
     *
     * @return array Résumé des actions entreprises (entrées, sorties, notifications envoyées)
     */
    public function processClientLocation(Client $client, float $latitude, float $longitude): array
    {
        // 1. Récupérer les restaurants pour lesquels le client possède une carte de fidélité active
        $cards = LoyaltyCard::with('restaurant')
            ->where('client_id', $client->id)
            ->where('status', 'active')
            ->get();

        $notificationsSent = 0;
        $entriesDetected = 0;
        $exitsDetected = 0;

        foreach ($cards as $card) {
            /** @var Restaurant|null $restaurant */
            $restaurant = $card->restaurant;

            if (! $restaurant || ! $restaurant->hasLocation()) {
                continue;
            }

            // Vérifier si la fonctionnalité de proximité est active côté marchand
            if (! $restaurant->isProximityActive()) {
                continue;
            }

            $settings = $restaurant->proximitySettings();
            $radius = (float) ($settings['radius_m'] ?? 500);

            // Coordonnées du restaurant
            $restLat = (float) $restaurant->location->latitude;
            $restLng = (float) $restaurant->location->longitude;

            // Calcul de la distance en mètres
            $distance = self::calculateDistance($latitude, $longitude, $restLat, $restLng);

            // Récupérer ou initialiser l'état de géolocalisation pour ce couple client/restaurant
            /** @var ClientRestaurantGeoOptin $geoOptin */
            $geoOptin = ClientRestaurantGeoOptin::firstOrCreate(
                [
                    'client_id' => $client->id,
                    'restaurant_id' => $restaurant->id,
                ],
                [
                    'opted_in' => true,
                    'radius_m' => (int) $radius,
                    'is_inside' => false,
                ]
            );

            // Machine à état avec hystérésis
            if ($distance <= $radius) {
                // Le client est à l'intérieur de la zone de déclenchement
                if (! $geoOptin->is_inside) {
                    // VÉRITABLE ENTRÉE DANS LA ZONE
                    $entriesDetected++;

                    $canSend = $this->evaluateEligibility($client, $restaurant, $geoOptin);

                    if ($canSend) {
                        $this->sendProximityNotification($client, $restaurant, $card, $settings, $distance);
                        $geoOptin->update([
                            'is_inside' => true,
                            'last_entered_at' => now(),
                            'last_notified_at' => now(),
                        ]);
                        $notificationsSent++;
                    } else {
                        // Le client est entré mais ne doit pas recevoir de notification (anti-spam / cooldown)
                        $geoOptin->update([
                            'is_inside' => true,
                            'last_entered_at' => now(),
                        ]);
                    }
                }
                // Si $geoOptin->is_inside == true : le client reste dans la zone, AUCUNE notification répétée.
            } elseif ($distance > ($radius + self::HYSTERESIS_BUFFER_METERS)) {
                // Le client est franchement en dehors de la zone (au-delà du tampon d'hystérésis)
                if ($geoOptin->is_inside) {
                    // VÉRITABLE SORTIE DE LA ZONE
                    $exitsDetected++;
                    $geoOptin->update([
                        'is_inside' => false,
                        'last_exited_at' => now(),
                    ]);
                }
            }
            // Si $radius < $distance <= $radius + 50m : zone tampon d'hystérésis, état conservé.
        }

        return [
            'notifications_sent' => $notificationsSent,
            'entries_detected' => $entriesDetected,
            'exits_detected' => $exitsDetected,
        ];
    }

    /**
     * Évalue l'ensemble des règles d'éligibilité et anti-spam avant tout envoi.
     */
    public function evaluateEligibility(
        Client $client,
        Restaurant $restaurant,
        ClientRestaurantGeoOptin $geoOptin
    ): bool {
        // 1. Le client accepte les notifications (device tokens et non-désabonné)
        if (! $geoOptin->opted_in) {
            return false;
        }

        if ($client->deviceTokens()->count() === 0) {
            return false;
        }

        // 2. L'établissement est actif et configuré
        if (! $restaurant->isProximityActive()) {
            return false;
        }

        // 3. Règle anti-spam : délai minimum de 24h depuis la dernière notification
        if ($geoOptin->last_notified_at !== null) {
            if ($geoOptin->last_notified_at->copy()->addHours(self::COOLDOWN_HOURS)->isFuture()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Déclenche l'envoi de la notification via le dispatcher unifié.
     */
    protected function sendProximityNotification(
        Client $client,
        Restaurant $restaurant,
        LoyaltyCard $card,
        array $settings,
        float $distance
    ): void {
        $title = $settings['title'] ?? 'Vous êtes tout près de nous !';
        $body = $settings['message'] ?? 'Passez nous voir et profitez de vos avantages fidélité.';

        // Remplacement dynamique du nom du restaurant si présent sous forme de variable
        $title = str_replace('{restaurant_name}', $restaurant->name, $title);
        $body = str_replace('{restaurant_name}', $restaurant->name, $body);

        $this->notificationDispatcher->send(
            $client,
            'proximity_alert',
            $title,
            $body,
            [
                'type' => 'proximity_alert',
                'restaurant_id' => (string) $restaurant->id,
                'restaurant_name' => $restaurant->name,
                'card_id' => (string) $card->id,
                'distance_m' => (int) round($distance),
            ]
        );
    }

    /**
     * Formule de Haversine pour calculer la distance exacte en mètres entre deux points GPS.
     */
    public static function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000.0; // Rayon moyen de la Terre en mètres
        $latDelta = deg2rad($lat2 - $lat1);
        $lonDelta = deg2rad($lon2 - $lon1);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
