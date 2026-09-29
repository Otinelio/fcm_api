<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RestaurantSubscriptionResource\Pages;
use App\Models\RestaurantSubscription;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RestaurantSubscriptionResource extends Resource
{
    protected static ?string $model = RestaurantSubscription::class;

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Abonnements';

    protected static ?string $pluralModelLabel = 'Abonnements Établissements';

    protected static ?string $modelLabel = 'Abonnement Établissement';

    protected static ?int $navigationSort = 2;

    /**
     * Temporairement indisponible : Abonnements / FedaPay désactivés.
     * Conserve le code pour réactivation ultérieure sans suppression.
     */
    public static function canAccess(): bool
    {
        return false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Détails de l\'Abonnement')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('restaurant_id')
                                    ->label('Établissement')
                                    ->relationship('restaurant', 'name')
                                    ->getOptionLabelFromRecordUsing(fn ($record): string => (string) ($record->name ?: "Établissement #{$record->id} (" . ($record->email ?: 'Sans nom') . ")"))
                                    ->searchable()
                                    ->preload()
                                    ->required(),

                                Forms\Components\Select::make('plan_id')
                                    ->label('Formule')
                                    ->relationship('plan', 'name')
                                    ->searchable()
                                    ->preload()
                                    ->required(),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->label('Statut')
                                    ->options([
                                        'active' => 'Actif',
                                        'pending' => 'En attente',
                                        'canceled' => 'Annulé',
                                        'expired' => 'Expiré',
                                    ])
                                    ->default('active')
                                    ->required(),

                                Forms\Components\Select::make('billing_cycle')
                                    ->label('Périodicité')
                                    ->options([
                                        'monthly' => 'Mensuel',
                                        'yearly' => 'Annuel',
                                    ])
                                    ->default('monthly')
                                    ->required(),

                                Forms\Components\TextInput::make('payment_reference')
                                    ->label('Réf. Paiement')
                                    ->maxLength(255),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\DateTimePicker::make('starts_at')
                                    ->label('Début'),

                                Forms\Components\DateTimePicker::make('ends_at')
                                    ->label('Fin / Renouvellement'),

                                Forms\Components\DateTimePicker::make('canceled_at')
                                    ->label('Date d\'Annulation'),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('restaurant.name')
                    ->label('Établissement')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('plan.name')
                    ->label('Formule')
                    ->badge()
                    ->color('primary')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'warning',
                        'canceled', 'expired' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('billing_cycle')
                    ->label('Cycle')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'monthly' => 'Mensuel',
                        'yearly' => 'Annuel',
                        default => $state,
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('starts_at')
                    ->label('Début')
                    ->dateTime('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('ends_at')
                    ->label('Échéance')
                    ->dateTime('d/m/Y')
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_reference')
                    ->label('Réf.')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'active' => 'Actif',
                        'pending' => 'En attente',
                        'canceled' => 'Annulé',
                        'expired' => 'Expiré',
                    ]),

                Tables\Filters\SelectFilter::make('billing_cycle')
                    ->label('Cycle')
                    ->options([
                        'monthly' => 'Mensuel',
                        'yearly' => 'Annuel',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucun abonnement actif')
            ->emptyStateDescription('Les souscriptions souscrites par les marchands apparaîtront ici.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRestaurantSubscriptions::route('/'),
            'create' => Pages\CreateRestaurantSubscription::route('/create'),
            'edit' => Pages\EditRestaurantSubscription::route('/{record}/edit'),
        ];
    }
}
