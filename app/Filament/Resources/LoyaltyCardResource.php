<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoyaltyCardResource\Pages;
use App\Models\LoyaltyCard;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LoyaltyCardResource extends Resource
{
    protected static ?string $model = LoyaltyCard::class;

    protected static ?string $navigationGroup = 'Clients';

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Cartes';

    protected static ?string $pluralModelLabel = 'Cartes de Fidélité';

    protected static ?string $modelLabel = 'Carte de Fidélité';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Rattachement & Titulaire')
                    ->description('Liaison entre le client mobile et le programme du commerce.')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('client_id')
                                    ->label('Client Mobile')
                                    ->relationship('client', 'phone')
                                    ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->full_name} ({$record->phone})")
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\Select::make('restaurant_id')
                                    ->label('Établissement')
                                    ->relationship('restaurant', 'name')
                                    ->getOptionLabelFromRecordUsing(fn ($record): string => (string) ($record->name ?: "Établissement #{$record->id} (" . ($record->email ?: 'Sans nom') . ")"))
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\Select::make('loyalty_program_id')
                                    ->label('Programme de Fidélité')
                                    ->relationship('loyaltyProgram', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                            ]),
                    ]),

                Forms\Components\Section::make('Statut & Progression du Client')
                    ->schema([
                        Forms\Components\Grid::make(4)
                            ->schema([
                                Forms\Components\TextInput::make('card_code')
                                    ->label('Code de la Carte')
                                    ->required()
                                    ->maxLength(255)
                                    ->disabledOn('edit'),

                                Forms\Components\Select::make('status')
                                    ->label('Statut')
                                    ->options([
                                        'active' => 'Active',
                                        'completed' => 'Complétée',
                                        'expired' => 'Expirée',
                                    ])
                                    ->default('active')
                                    ->required(),

                                Forms\Components\TextInput::make('cashback_balance_fcfa')
                                    ->label('Solde Cashback (FCFA)')
                                    ->numeric()
                                    ->default(0)
                                    ->suffix('FCFA')
                                    ->required(),

                                Forms\Components\TextInput::make('cycles_completed')
                                    ->label('Cycles Terminés')
                                    ->numeric()
                                    ->default(0)
                                    ->required(),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('vip_tier')
                                    ->label('Niveau VIP')
                                    ->placeholder('Ex: Gold, Platine')
                                    ->maxLength(255),

                                Forms\Components\DateTimePicker::make('last_activity_at')
                                    ->label('Dernière Activité'),

                                Forms\Components\DateTimePicker::make('completed_at')
                                    ->label('Date d\'Achèvement'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('restaurant.logo_url')
                    ->label('')
                    ->circular()
                    ->size(38)
                    ->defaultImageUrl(fn (?LoyaltyCard $record): ?string => \App\Support\AvatarHelper::forRestaurant($record?->restaurant)),

                Tables\Columns\TextColumn::make('client.full_name')
                    ->label('Titulaire')
                    ->description(fn (LoyaltyCard $record): ?string => $record->client?->phone)
                    ->searchable(['first_name', 'last_name'])
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('restaurant.name')
                    ->label('Établissement')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('loyaltyProgram.name')
                    ->label('Programme')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('loyaltyProgram.type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'stamp' => '🎫 Tampons',
                        'points' => '⭐ Points',
                        'cashback' => '💰 Cashback',
                        'vip' => '👑 VIP',
                        default => $state ?? '—',
                    })
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'active' => 'Active',
                        'completed' => 'Complétée',
                        'expired' => 'Expirée',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'completed' => 'primary',
                        'expired' => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),

                Tables\Columns\ViewColumn::make('percent')
                    ->label('Progression')
                    ->view('filament.resources.loyalty-card.components.progress-cell')
                    ->sortable(query: function ($query, $direction) {
                        $safeDirection = in_array(strtolower($direction), ['asc', 'desc'], true) ? strtolower($direction) : 'asc';
                        return $query->orderBy('progress->stamps_current', $safeDirection);
                    }),

                Tables\Columns\TextColumn::make('cycles_completed')
                    ->label('Cycles')
                    ->badge()
                    ->color('gray')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('last_activity_at')
                    ->label('Dernier Passage')
                    ->since()
                    ->sortable()
                    ->color('gray'),
            ])
            ->defaultSort('last_activity_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'active' => 'Active',
                        'completed' => 'Complétée',
                        'expired' => 'Expirée',
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

                Tables\Filters\SelectFilter::make('loyaltyProgram.type')
                    ->label('Type de programme')
                    ->relationship('loyaltyProgram', 'type')
                    ->options([
                        'stamp' => 'Tampons',
                        'points' => 'Points',
                        'cashback' => 'Cashback',
                        'vip' => 'VIP',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucune carte de fidélité')
            ->emptyStateDescription('Les cartes créées par les clients mobiles apparaîtront ici.')
            ->emptyStateIcon('heroicon-o-credit-card');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoyaltyCards::route('/'),
            'create' => Pages\CreateLoyaltyCard::route('/create'),
            'view' => Pages\ViewLoyaltyCard::route('/{record}'),
            'edit' => Pages\EditLoyaltyCard::route('/{record}/edit'),
        ];
    }
}
