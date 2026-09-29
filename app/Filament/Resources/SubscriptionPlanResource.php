<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionPlanResource\Pages;
use App\Models\SubscriptionPlan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SubscriptionPlanResource extends Resource
{
    protected static ?string $model = SubscriptionPlan::class;

    protected static ?string $navigationGroup = 'Finance';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Formules';

    protected static ?string $pluralModelLabel = 'Formules d\'Abonnement';

    protected static ?string $modelLabel = 'Formule d\'Abonnement';

    protected static ?int $navigationSort = 1;

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
                Forms\Components\Section::make('Identité & Tarification')
                    ->schema([
                        Forms\Components\Grid::make(4)
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Nom de la Formule')
                                    ->placeholder('Ex: Starter, Pro, Entreprise')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('slug')
                                    ->label('Identifiant Unique (slug)')
                                    ->placeholder('starter, pro')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('price_monthly')
                                    ->label('Tarif Mensuel')
                                    ->numeric()
                                    ->default(0)
                                    ->suffix('FCFA')
                                    ->required(),

                                Forms\Components\TextInput::make('price_yearly')
                                    ->label('Tarif Annuel')
                                    ->numeric()
                                    ->default(0)
                                    ->suffix('FCFA')
                                    ->required(),
                            ]),
                    ]),

                Forms\Components\Section::make('Quotas & Limites')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('max_staff')
                                    ->label('Comptes Personnel max')
                                    ->numeric()
                                    ->default(1)
                                    ->required(),

                                Forms\Components\TextInput::make('max_loyalty_programs')
                                    ->label('Programmes de fidélité max')
                                    ->numeric()
                                    ->default(1)
                                    ->required(),

                                Forms\Components\TextInput::make('max_clients')
                                    ->label('Clients enregistrés max')
                                    ->numeric()
                                    ->placeholder('Illimité si vide'),
                            ]),
                    ]),

                Forms\Components\Section::make('Options & Modules Inclus')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Toggle::make('allows_cashback')
                                    ->label('Cashback en FCFA'),

                                Forms\Components\Toggle::make('allows_vip')
                                    ->label('Paliers VIP'),

                                Forms\Components\Toggle::make('allows_auto_notifications')
                                    ->label('Push Automatiques'),

                                Forms\Components\Toggle::make('allows_geolocation')
                                    ->label('Géolocalisation & Proximité'),

                                Forms\Components\Toggle::make('allows_marketplace')
                                    ->label('Présence Marketplace'),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('Formule Active & Disponible')
                                    ->default(true),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Formule')
                    ->description(fn (SubscriptionPlan $record): ?string => $record->slug)
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('price_monthly')
                    ->label('Mensuel')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                    ->color('primary')
                    ->weight('semibold')
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('price_yearly')
                    ->label('Annuel')
                    ->formatStateUsing(fn ($state) => number_format($state, 0, ',', ' ').' FCFA')
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('max_staff')
                    ->label('Staff Max')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('max_loyalty_programs')
                    ->label('Programmes Max')
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Actif')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),
            ])
            ->defaultSort('price_monthly', 'asc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Statut Actif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Aucune formule configurée')
            ->emptyStateDescription('Créez vos forfaits d\'abonnement pour les commerçants.')
            ->emptyStateIcon('heroicon-o-rectangle-stack');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptionPlans::route('/'),
            'create' => Pages\CreateSubscriptionPlan::route('/create'),
            'edit' => Pages\EditSubscriptionPlan::route('/{record}/edit'),
        ];
    }
}
