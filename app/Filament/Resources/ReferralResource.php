<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReferralResource\Pages;
use App\Models\Referral;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ReferralResource extends Resource
{
    protected static ?string $model = Referral::class;

    protected static ?string $navigationGroup = 'Clients';

    protected static ?string $navigationIcon = 'heroicon-o-share';

    protected static ?string $navigationLabel = 'Parrainages';

    protected static ?string $pluralModelLabel = 'Parrainages';

    protected static ?string $modelLabel = 'Parrainage';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Détails du Parrainage')
                    ->description('Liaison entre le parrain (client existant) et le filleul (nouveau client).')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('restaurant_id')
                                    ->label('Établissement')
                                    ->relationship('restaurant', 'name')
                                    ->getOptionLabelFromRecordUsing(fn ($record): string => (string) ($record->name ?: "Établissement #{$record->id} (" . ($record->email ?: 'Sans nom') . ")"))
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\Select::make('referrer_client_id')
                                    ->label('Parrain')
                                    ->relationship('referrerClient', 'phone')
                                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->full_name} ({$record->phone})")
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\Select::make('referred_client_id')
                                    ->label('Filleul')
                                    ->relationship('referredClient', 'phone')
                                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->full_name} ({$record->phone})")
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->label('Statut')
                                    ->options([
                                        'pending' => 'En attente 1er passage',
                                        'validated' => 'Validé & Récompensé',
                                        'expired' => 'Expiré',
                                    ])
                                    ->default('pending')
                                    ->required(),

                                Forms\Components\DateTimePicker::make('validated_at')
                                    ->label('Date de Validation'),

                                Forms\Components\Select::make('referrer_card_id')
                                    ->label('Carte Parrain')
                                    ->relationship('referrerCard', 'card_code')
                                    ->searchable(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Invitation le')
                    ->dateTime('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('restaurant.name')
                    ->label('Établissement')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('referrerClient.full_name')
                    ->label('Parrain')
                    ->description(fn (Referral $record): ?string => $record->referrerClient?->phone)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('referredClient.full_name')
                    ->label('Filleul')
                    ->description(fn (Referral $record): ?string => $record->referredClient?->phone)
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'validated' => 'success',
                        'pending' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'validated' => 'Validé',
                        'pending' => 'En attente',
                        default => ucfirst((string) $state),
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('validated_at')
                    ->label('Validé le')
                    ->dateTime('d/m/Y H:i')
                    ->color('gray')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'pending' => 'En attente',
                        'validated' => 'Validé',
                    ]),

                Tables\Filters\SelectFilter::make('restaurant_id')
                    ->label('Établissement')
                    ->options(fn (): array => \App\Models\Restaurant::query()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn ($r) => [$r->id => (string) ($r->name ?: "Établissement #{$r->id} (" . ($r->email ?: 'Sans nom') . ")")])
                        ->all()
                    )
                    ->searchable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucun parrainage enregistré')
            ->emptyStateDescription('Les invitations entre clients et leurs filleuls apparaîtront ici.')
            ->emptyStateIcon('heroicon-o-share');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferrals::route('/'),
            'create' => Pages\CreateReferral::route('/create'),
            'edit' => Pages\EditReferral::route('/{record}/edit'),
        ];
    }
}
