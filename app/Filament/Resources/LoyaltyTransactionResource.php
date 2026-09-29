<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LoyaltyTransactionResource\Pages;
use App\Models\LoyaltyTransaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class LoyaltyTransactionResource extends Resource
{
    protected static ?string $model = LoyaltyTransaction::class;

    protected static ?string $navigationGroup = 'Clients';

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'Transactions';

    protected static ?string $pluralModelLabel = 'Transactions de Fidélité';

    protected static ?string $modelLabel = 'Transaction de Fidélité';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Détails de l\'Opération')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('loyalty_card_id')
                                    ->label('Carte de Fidélité')
                                    ->relationship('loyaltyCard', 'card_code')
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\Select::make('staff_user_id')
                                    ->label('Opérateur / Serveur')
                                    ->relationship('staffUser', 'name')
                                    ->searchable()
                                    ->preload(),

                                Forms\Components\Select::make('type')
                                    ->label('Type de Transaction')
                                    ->options([
                                        'stamp' => 'Tampon / Passage',
                                        'reward' => 'Récompense débloquée',
                                        'cashback' => 'Crédit Cashback',
                                    ])
                                    ->required(),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('value')
                                    ->label('Valeur attribuée')
                                    ->numeric()
                                    ->default(1)
                                    ->required(),

                                Forms\Components\TextInput::make('montant_commande_fcfa')
                                    ->label('Montant de la commande (FCFA)')
                                    ->numeric()
                                    ->suffix('FCFA'),

                                Forms\Components\TextInput::make('validation_method')
                                    ->label('Méthode de Validation')
                                    ->placeholder('qr_code, manual, nfc')
                                    ->maxLength(255),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->label('Statut')
                                    ->options([
                                        'valid' => 'Validée',
                                        'canceled' => 'Annulée',
                                    ])
                                    ->default('valid')
                                    ->required(),

                                Forms\Components\Toggle::make('is_suspicious')
                                    ->label('Activité Suspecte')
                                    ->helperText('Signaler pour vérification anti-fraude'),

                                Forms\Components\TextInput::make('suspicious_reason')
                                    ->label('Motif de la suspicion')
                                    ->placeholder('Ex: Multiples scans en quelques minutes')
                                    ->maxLength(255),
                            ]),
                    ]),

                Forms\Components\Section::make('Historique d\'Annulation')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('canceled_by_staff_user_id')
                                    ->label('Annulé par')
                                    ->relationship('canceledByStaffUser', 'name'),

                                Forms\Components\DateTimePicker::make('canceled_at')
                                    ->label('Date d\'Annulation'),
                            ]),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Date & Heure')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('loyaltyCard.card_code')
                    ->label('Carte')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('loyaltyCard.restaurant.name')
                    ->label('Établissement')
                    ->weight('semibold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('loyaltyCard.client.full_name')
                    ->label('Client')
                    ->searchable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower($state)) {
                        'stamp', 'points' => 'info',
                        'reward', 'reward_unlocked' => 'success',
                        'cashback' => 'warning',
                        default => 'primary',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('value')
                    ->label('Points')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('montant_commande_fcfa')
                    ->label('Commande')
                    ->formatStateUsing(fn ($state) => $state ? number_format($state, 0, ',', ' ').' F' : '—')
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('staffUser.name')
                    ->label('Opérateur')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'valid' => 'success',
                        'canceled' => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_suspicious')
                    ->label('Suspect')
                    ->boolean()
                    ->trueIcon('heroicon-m-exclamation-triangle')
                    ->trueColor('danger')
                    ->falseIcon('heroicon-m-check')
                    ->falseColor('gray'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options([
                        'stamp' => 'Tampon / Passage',
                        'reward' => 'Récompense',
                        'cashback' => 'Cashback',
                    ]),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'valid' => 'Validée',
                        'canceled' => 'Annulée',
                    ]),

                Tables\Filters\TernaryFilter::make('is_suspicious')
                    ->label('Transactions Suspectes'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucune transaction de fidélité')
            ->emptyStateDescription('Toutes les transactions effectuées en caisse s\'afficheront ici.')
            ->emptyStateIcon('heroicon-o-arrows-right-left');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLoyaltyTransactions::route('/'),
            'create' => Pages\CreateLoyaltyTransaction::route('/create'),
            'edit' => Pages\EditLoyaltyTransaction::route('/{record}/edit'),
        ];
    }
}
