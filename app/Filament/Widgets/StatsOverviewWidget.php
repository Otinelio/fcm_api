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
    protected function getStats(): array
    {
        $totalRestaurants = Restaurant::count();
        $activeRestaurants = Restaurant::where('status', 'active')->count();
        $totalClients = Client::count();
        $totalLoyaltyCards = LoyaltyCard::count();
        $totalTransactions = LoyaltyTransaction::count();
        $approvedPaymentsSum = PaymentTransaction::where('status', 'approved')->sum('amount_xof');

        return [
            Stat::make('Restaurants', "{$activeRestaurants} / {$totalRestaurants}")
                ->description('Actifs / Inscrits au total')
                ->descriptionIcon('heroicon-m-building-storefront')
                ->color('success'),

            Stat::make('Clients Mobiles', number_format($totalClients))
                ->description('Utilisateurs inscrits sur l\'app')
                ->descriptionIcon('heroicon-m-users')
                ->color('primary'),

            Stat::make('Cartes de Fidélité', number_format($totalLoyaltyCards))
                ->description('Cartes actives réparties')
                ->descriptionIcon('heroicon-m-credit-card')
                ->color('warning'),

            Stat::make('Transactions Tampons', number_format($totalTransactions))
                ->description('Tampons attribués & débloqués')
                ->descriptionIcon('heroicon-m-arrows-right-left')
                ->color('info'),

            Stat::make('Revenus Abonnements (FedaPay)', number_format($approvedPaymentsSum, 0, ',', ' ').' FCFA')
                ->description('Total des paiements validés')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),
        ];
    }
}
