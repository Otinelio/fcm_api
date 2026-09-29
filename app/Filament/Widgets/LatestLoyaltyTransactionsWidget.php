<?php

namespace App\Filament\Widgets;

use App\Models\LoyaltyTransaction;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestLoyaltyTransactionsWidget extends BaseWidget
{
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Dernières Activités & Transactions Fidélité';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                LoyaltyTransaction::query()
                    ->with(['loyaltyCard.client', 'loyaltyCard.restaurant', 'staffUser'])
                    ->latest()
                    ->limit(6)
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date & Heure')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('loyaltyCard.card_code')
                    ->label('Code Carte')
                    ->badge()
                    ->color('gray')
                    ->searchable(),

                Tables\Columns\TextColumn::make('loyaltyCard.restaurant.name')
                    ->label('Établissement')
                    ->weight('semibold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('loyaltyCard.client.full_name')
                    ->label('Client')
                    ->default(fn ($record) => $record->loyaltyCard?->client?->phone ?? 'Client Anonyme'),

                Tables\Columns\TextColumn::make('type')
                    ->label('Opération')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'stamp', 'points' => 'info',
                        'reward', 'reward_unlocked' => 'success',
                        'cashback' => 'warning',
                        default => 'primary',
                    }),

                Tables\Columns\TextColumn::make('value')
                    ->label('Points / Tampons')
                    ->numeric()
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('montant_commande_fcfa')
                    ->label('Montant Cde')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state, 0, ',', ' ').' F' : '—')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'valid' => 'success',
                        'canceled' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->emptyStateHeading('Aucune transaction récente')
            ->emptyStateDescription('Les interactions clients (tampons, déblocages) s\'afficheront ici en direct.')
            ->emptyStateIcon('heroicon-o-arrows-right-left');
    }
}
