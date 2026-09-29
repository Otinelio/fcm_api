<?php

namespace App\Filament\Resources\NotificationCampaignResource\RelationManagers;

use App\Filament\Resources\ClientResource;
use App\Models\NotificationLog;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LogsRelationManager extends RelationManager
{
    protected static string $relationship = 'logs';

    protected static ?string $title = 'Destinataires & Historique de distribution';

    protected static ?string $modelLabel = 'Destinataire';

    protected static ?string $pluralModelLabel = 'Destinataires';

    protected static ?string $icon = 'heroicon-o-paper-airplane';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('client_id')
            ->defaultSort('sent_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('client_display')
                    ->label('Client Destinataire')
                    ->weight('bold')
                    ->getStateUsing(fn (NotificationLog $record): string => $record->client ? $record->client->full_name : "Client #{$record->client_id}")
                    ->description(fn (NotificationLog $record): string => $record->client?->phone ? "Tél: {$record->client->phone}" : ($record->client?->email ?? 'Sans contact enregistré'))
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                        return $query->whereHas('client', function (Builder $q) use ($escapedSearch) {
                            $q->where('first_name', 'like', "%{$escapedSearch}%")
                                ->orWhere('last_name', 'like', "%{$escapedSearch}%")
                                ->orWhere('phone', 'like', "%{$escapedSearch}%")
                                ->orWhere('email', 'like', "%{$escapedSearch}%");
                        });
                    })
                    ->url(fn (NotificationLog $record): ?string => $record->client_id ? ClientResource::getUrl('edit', ['record' => $record->client_id]) : null)
                    ->openUrlInNewTab(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Transmission FCM')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'sent' => 'Envoyé avec succès',
                        'failed' => 'Échec d\'acheminement',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'sent' => 'success',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn (string $state): string => match ($state) {
                        'sent' => 'heroicon-o-check-circle',
                        'failed' => 'heroicon-o-x-circle',
                        default => 'heroicon-o-clock',
                    }),

                Tables\Columns\TextColumn::make('is_read')
                    ->label('Ouverture / Lecture')
                    ->badge()
                    ->getStateUsing(function (NotificationLog $record): string {
                        $readAt = $record->read_at;
                        if ($readAt) {
                            return 'Lu le ' . $readAt->format('d/m/Y H:i');
                        }
                        return 'Non ouvert';
                    })
                    ->color(fn (string $state): string => str_starts_with($state, 'Lu') ? 'success' : 'gray')
                    ->icon(fn (string $state): string => str_starts_with($state, 'Lu') ? 'heroicon-o-eye' : 'heroicon-o-eye-slash'),

                Tables\Columns\TextColumn::make('readable_failure_reason')
                    ->label('Détail / Motif')
                    ->placeholder('Acheminé normalement')
                    ->color(fn (?string $state): string => $state ? 'danger' : 'gray')
                    ->wrap(),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Date & Heure')
                    ->dateTime('d/m/Y H:i:s')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut FCM')
                    ->options([
                        'sent' => 'Envoyé avec succès',
                        'failed' => 'Échoué',
                    ]),

                Tables\Filters\Filter::make('is_read')
                    ->label('Uniquement les notifications lues')
                    ->query(function (Builder $query): Builder {
                        return $query->whereHas('client.notifications', function ($q) {
                            $q->where('type', 'campaign')
                                ->whereNotNull('read_at');
                        });
                    }),
            ])
            ->emptyStateHeading('Aucun historique de distribution')
            ->emptyStateDescription('Les envois et confirmations de réception FCM apparaîtront ici dès que la campagne sera diffusée.')
            ->emptyStateIcon('heroicon-o-paper-airplane');
    }
}
