<?php

namespace App\Filament\Widgets;

use App\Models\Restaurant;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class LatestRestaurantsWidget extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    protected static ?string $heading = 'Nouveaux Marchands Inscrits';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Restaurant::query()
                    ->with('plan')
                    ->latest()
                    ->limit(5)
            )
            ->paginated(false)
            ->columns([
                Tables\Columns\ImageColumn::make('logo_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn (?Restaurant $record): ?string => \App\Support\AvatarHelper::forRestaurant($record)),

                Tables\Columns\TextColumn::make('name')
                    ->label('Établissement')
                    ->description(fn (Restaurant $record): ?string => $record->category)
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('city')
                    ->label('Ville')
                    ->formatStateUsing(fn ($state, Restaurant $record) => $state ? "{$state} ({$record->country})" : 'Non renseigné')
                    ->color('gray'),

                Tables\Columns\TextColumn::make('plan.name')
                    ->label('Formule')
                    ->badge()
                    ->color('primary')
                    ->default('Gratuit'),

                Tables\Columns\TextColumn::make('sms_credits')
                    ->label('Quota Push')
                    ->badge()
                    ->color(fn ($state) => $state > 20 ? 'success' : 'warning')
                    ->formatStateUsing(fn ($state) => "{$state} restants"),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'suspended' => 'danger',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date Inscription')
                    ->dateTime('d/m/Y')
                    ->color('gray'),
            ])
            ->emptyStateHeading('Aucun établissement enregistré')
            ->emptyStateDescription('Les établissements qui s\'inscrivent apparaîtront ici.')
            ->emptyStateIcon('heroicon-o-building-storefront');
    }
}
