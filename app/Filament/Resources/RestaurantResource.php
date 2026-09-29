<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RestaurantResource\Pages;
use App\Models\Restaurant;
use App\Models\SubscriptionPlan;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class RestaurantResource extends Resource
{
    protected static ?string $model = Restaurant::class;

    protected static ?string $navigationGroup = 'Marchands';

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Établissements';

    protected static ?string $pluralModelLabel = 'Établissements';

    protected static ?string $modelLabel = 'Établissement';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Détails de l\'Établissement')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Identité & Contact')
                            ->icon('heroicon-o-identification')
                            ->schema([
                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Nom du commerce')
                                            ->placeholder('Ex: Le Gourmet Béninois')
                                            ->required()
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('category')
                                            ->label('Catégorie')
                                            ->placeholder('Ex: Restaurant, Fast Food, Salon...')
                                            ->maxLength(255),

                                        Forms\Components\Select::make('status')
                                            ->label('Statut du compte')
                                            ->options([
                                                'active' => 'Actif',
                                                'inactive' => 'Inactif',
                                                'suspended' => 'Suspendu',
                                            ])
                                            ->default('active')
                                            ->required(),
                                    ]),

                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\TextInput::make('email')
                                            ->label('Adresse E-mail')
                                            ->email()
                                            ->required()
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('phone')
                                            ->label('Téléphone')
                                            ->tel()
                                            ->placeholder('+229 01XXXXXXXX')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('short_code')
                                            ->label('Code Court Manuel (8 car.)')
                                            ->maxLength(8)
                                            ->disabledOn('edit')
                                            ->helperText('Généré automatiquement si vide'),
                                    ]),

                                Forms\Components\TextInput::make('logo_url')
                                    ->label('URL du Logo')
                                    ->url()
                                    ->placeholder('https://.../logo.png')
                                    ->columnSpanFull(),

                                Forms\Components\Textarea::make('description')
                                    ->label('Description / Bio')
                                    ->rows(2)
                                    ->columnSpanFull(),
                            ]),

                        Forms\Components\Tabs\Tab::make('Localisation')
                            ->icon('heroicon-o-map-pin')
                            ->schema([
                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\TextInput::make('city')
                                            ->label('Ville')
                                            ->placeholder('Cotonou')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('country')
                                            ->label('Pays')
                                            ->placeholder('Bénin')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('address')
                                            ->label('Adresse physique')
                                            ->placeholder('Haie Vive, Rue 320')
                                            ->maxLength(255),
                                    ]),
                            ]),

                        Forms\Components\Tabs\Tab::make('Abonnement & SMS')
                            ->icon('heroicon-o-banknotes')
                            ->schema([
                                Forms\Components\Grid::make(3)
                                    ->schema([
                                        Forms\Components\Select::make('plan_id')
                                            ->label('Formule d\'abonnement')
                                            ->relationship('plan', 'name')
                                            ->searchable()
                                            ->preload()
                                            ->nullable(),

                                        Forms\Components\TextInput::make('sms_credits')
                                            ->label('Crédits SMS')
                                            ->numeric()
                                            ->default(100)
                                            ->required(),

                                        Forms\Components\TextInput::make('password')
                                            ->label('Mot de passe')
                                            ->password()
                                            ->revealable()
                                            ->dehydrated(fn ($state) => filled($state))
                                            ->required(fn (string $context): bool => $context === 'create')
                                            ->helperText('Laissez vide pour conserver l\'actuel en modification'),
                                    ]),
                            ]),

                        Forms\Components\Tabs\Tab::make('Réseaux Sociaux')
                            ->icon('heroicon-o-share')
                            ->schema([
                                Forms\Components\Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('whatsapp')
                                            ->label('Numéro WhatsApp')
                                            ->placeholder('+229XXXXXXXX')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('instagram')
                                            ->label('Instagram')
                                            ->placeholder('@compte ou lien')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('facebook')
                                            ->label('Page Facebook')
                                            ->placeholder('facebook.com/... ou page')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('tiktok')
                                            ->label('TikTok')
                                            ->placeholder('@tiktok')
                                            ->maxLength(255),
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo_url')
                    ->label('')
                    ->circular()
                    ->defaultImageUrl(fn (?Restaurant $record): ?string => \App\Support\AvatarHelper::forRestaurant($record)),

                Tables\Columns\TextColumn::make('name')
                    ->label('Établissement')
                    ->description(fn (Restaurant $record): ?string => $record->category)
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Contact')
                    ->description(fn (Restaurant $record): ?string => $record->phone)
                    ->searchable(['email', 'phone'])
                    ->copyable()
                    ->copyMessage('Contact copié'),

                Tables\Columns\TextColumn::make('city')
                    ->label('Ville')
                    ->formatStateUsing(fn ($state, Restaurant $record) => $state ? "{$state} ({$record->country})" : '—')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('plan.name')
                    ->label('Formule')
                    ->badge()
                    ->color('primary')
                    ->default('Gratuit')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Statut')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'suspended' => 'danger',
                        default => 'warning',
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('sms_credits')
                    ->label('Quota Push FCM')
                    ->badge()
                    ->color(fn (int $state, Restaurant $record): string => match (true) {
                        $record->isFcmSuspended() => 'danger',
                        $state <= 0 => 'danger',
                        $state <= 10 => 'warning',
                        default => 'success',
                    })
                    ->formatStateUsing(fn (int $state, Restaurant $record): string => $record->isFcmSuspended()
                        ? 'FCM Suspendu'
                        : "{$state} restants"
                    )
                    ->description(fn (Restaurant $record): string => $record->isFcmSuspended()
                        ? ($record->fcm_suspension_reason ? "Raison : {$record->fcm_suspension_reason}" : 'Service suspendu')
                        : "{$record->consumed_quota} consommés / {$record->total_quota} total ({$record->quota_usage_percent}%)"
                    )
                    ->sortable(),

                Tables\Columns\TextColumn::make('short_code')
                    ->label('Code Court')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Inscrit le')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Statut')
                    ->options([
                        'active' => 'Actif',
                        'inactive' => 'Inactif',
                        'suspended' => 'Suspendu',
                    ]),

                Tables\Filters\SelectFilter::make('plan_id')
                    ->label('Formule')
                    ->relationship('plan', 'name'),

                Tables\Filters\SelectFilter::make('fcm_suspended')
                    ->label('Statut FCM')
                    ->options([
                        '0' => 'Actif',
                        '1' => 'Suspendu',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Quota')
                    ->icon('heroicon-o-chart-pie')
                    ->color('info'),

                Tables\Actions\Action::make('suspendFcm')
                    ->label('Suspendre FCM')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (Restaurant $record): bool => (bool) auth()->user()?->isSuperAdmin() && ! $record->isFcmSuspended())
                    ->requiresConfirmation()
                    ->modalHeading('Suspendre les notifications Push FCM')
                    ->modalDescription('Êtes-vous sûr de vouloir suspendre le service FCM pour cet établissement ? Les envois et programmations de campagnes seront immédiatement bloqués.')
                    ->modalSubmitActionLabel('Confirmer la suspension')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Motif de la suspension')
                            ->placeholder('Ex: Non-respect des règles éditoriales, fréquence excessive...')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (array $data, Restaurant $record): void {
                        $reason = $data['reason'] ?? null;
                        $record->suspendFcm($reason);

                        \Illuminate\Support\Facades\Log::warning('FCM_SUSPENDED_BY_ADMIN', [
                            'admin_id' => auth()->id(),
                            'admin_email' => auth()->user()?->email,
                            'restaurant_id' => $record->id,
                            'restaurant_name' => $record->name,
                            'reason' => $reason,
                            'timestamp' => now()->toIso8601String(),
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->warning()
                            ->title('Notifications FCM suspendues')
                            ->body("Le service Push FCM de « {$record->name} » est désormais suspendu.")
                            ->send();
                    }),

                Tables\Actions\Action::make('reactivateFcm')
                    ->label('Réactiver FCM')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Restaurant $record): bool => (bool) auth()->user()?->isSuperAdmin() && $record->isFcmSuspended())
                    ->requiresConfirmation()
                    ->modalHeading('Réactiver les notifications Push FCM')
                    ->modalDescription('Confirmez-vous la réactivation du service Push FCM pour cet établissement ? Le marchand pourra à nouveau envoyer et programmer des notifications.')
                    ->modalSubmitActionLabel('Confirmer la réactivation')
                    ->action(function (Restaurant $record): void {
                        $record->reactivateFcm();

                        \Illuminate\Support\Facades\Log::info('FCM_REACTIVATED_BY_ADMIN', [
                            'admin_id' => auth()->id(),
                            'admin_email' => auth()->user()?->email,
                            'restaurant_id' => $record->id,
                            'restaurant_name' => $record->name,
                            'timestamp' => now()->toIso8601String(),
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Notifications FCM réactivées')
                            ->body("Le service Push FCM de « {$record->name} » a été réactivé avec succès.")
                            ->send();
                    }),

                Tables\Actions\Action::make('quickCredit')
                    ->label('Créditer')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->visible(fn (): bool => (bool) auth()->user()?->isSuperAdmin())
                    ->form([
                        Forms\Components\TextInput::make('credits')
                            ->label('Crédits à ajouter')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                    ])
                    ->action(function (array $data, Restaurant $record): void {
                        $old = $record->sms_credits;
                        $amount = (int) $data['credits'];
                        $record->increment('sms_credits', $amount);

                        \Illuminate\Support\Facades\Log::info('CREDITS_ADDED_BY_ADMIN', [
                            'admin_id' => auth()->id(),
                            'admin_email' => auth()->user()?->email,
                            'restaurant_id' => $record->id,
                            'restaurant_name' => $record->name,
                            'old_credits' => $old,
                            'added_credits' => $amount,
                            'new_credits' => $old + $amount,
                            'timestamp' => now()->toIso8601String(),
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Crédits ajoutés')
                            ->body("{$amount} crédits ajoutés à {$record->name}. Solde : {$old} → " . ($old + $amount))
                            ->send();
                    }),

                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                // Aucune suppression en masse pour protéger l'intégrité des données des établissements
            ])
            ->emptyStateHeading('Aucun établissement trouvé')
            ->emptyStateDescription('Les établissements partenaires s\'afficheront ici.')
            ->emptyStateIcon('heroicon-o-building-storefront');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRestaurants::route('/'),
            'create' => Pages\CreateRestaurant::route('/create'),
            'view' => Pages\ViewRestaurant::route('/{record}'),
            'edit' => Pages\EditRestaurant::route('/{record}/edit'),
        ];
    }
}
