<?php

namespace App\Filament\Widgets;

use App\Models\Client;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyTransaction;
use App\Models\PaymentTransaction;
use App\Models\Restaurant;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $totalRestaurants = Restaurant::count();
        $activeRestaurants = Restaurant::where('status', 'active')->count();
        $totalClients = Client::count();
        $totalLoyaltyCards = LoyaltyCard::count();
        $totalTransactions = LoyaltyTransaction::count();
        $approvedPaymentsSum = PaymentTransaction::where('status', 'approved')->sum('amount_xof');

        return [
            Stat::make('Marchands Actifs', "{$activeRestaurants} / {$totalRestaurants}")
                ->description('Établissements actifs / total')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->chart([3, 5, 8, 10, 12, 15, $activeRestaurants])
                ->color('primary'),

            Stat::make('Clients Mobiles', number_format($totalClients, 0, ',', ' '))
                ->description('Utilisateurs enregistrés')
                ->descriptionIcon('heroicon-m-users')
                ->chart([5, 12, 25, 40, 65, 90, max($totalClients, 1)])
                ->color('info'),

            Stat::make('Cartes de Fidélité', number_format($totalLoyaltyCards, 0, ',', ' '))
                ->description('Cartes distribuées')
                ->descriptionIcon('heroicon-m-credit-card')
                ->chart([4, 10, 20, 35, 50, 70, max($totalLoyaltyCards, 1)])
                ->color('warning'),

            Stat::make('Transactions Fidélité', number_format($totalTransactions, 0, ',', ' '))
                ->description('Tampons & Récompenses')
                ->descriptionIcon('heroicon-m-arrows-right-left')
                ->chart([10, 25, 45, 60, 85, 120, max($totalTransactions, 1)])
                ->color('success'),

            // Stat FedaPay temporairement masquée - conserver pour réactivation ultérieure
            // Stat::make('Revenus Abonnements', number_format($approvedPaymentsSum, 0, ',', ' ').' FCFA')
            //     ->description('Volume validé FedaPay')
            //     ->descriptionIcon('heroicon-m-banknotes')
            //     ->chart([15000, 30000, 45000, 60000, 90000, max($approvedPaymentsSum, 10000)])
            //     ->color('success'),
        ];
    }
}
