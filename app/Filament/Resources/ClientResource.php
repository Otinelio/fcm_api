<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ClientResource\Pages;
use App\Models\Client;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static ?string $navigationGroup = 'Clients';

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Clients';

    protected static ?string $pluralModelLabel = 'Clients Mobiles';

    protected static ?string $modelLabel = 'Client Mobile';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identité & Profil Client')
                    ->description('Coordonnées du client et statut du compte mobile.')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('first_name')
                                    ->label('Prénom')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('last_name')
                                    ->label('Nom')
                                    ->maxLength(255),

                                Forms\Components\DatePicker::make('birthdate')
                                    ->label('Date de Naissance'),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('phone')
                                    ->label('Téléphone')
                                    ->tel()
                                    ->required()
                                    ->placeholder('+229XXXXXXXX')
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('email')
                                    ->label('Adresse E-mail')
                                    ->email()
                                    ->maxLength(255),

                                Forms\Components\DateTimePicker::make('phone_verified_at')
                                    ->label('Vérification Numéro')
                                    ->helperText('Date de validation OTP'),
                            ]),

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

                                Forms\Components\TextInput::make('avatar_url')
                                    ->label('URL Photo Profil')
                                    ->url()
                                    ->placeholder('https://...'),
                            ]),
                    ]),

                Forms\Components\Section::make('Connexion & Sécurité')
                    ->schema([
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('oauth_provider')
                                    ->label('Méthode de Connexion')
                                    ->disabled()
                                    ->placeholder('Mot de passe standard'),

                                Forms\Components\TextInput::make('oauth_id')
                                    ->label('ID OAuth')
                                    ->disabled()
                                    ->visible(fn ($record) => filled($record?->oauth_id)),

                                Forms\Components\TextInput::make('password')
                                    ->label('Nouveau Mot de Passe')
                                    ->password()
                                    ->revealable()
                                    ->dehydrated(fn ($state) => filled($state))
                                    ->required(fn (string $context): bool => $context === 'create')
                                    ->helperText('Laissez vide pour conserver l\'actuel'),
                            ]),

                        Forms\Components\Placeholder::make('auth_security_status')
                            ->label('Statut des tentatives de connexion (Rate Limiting)')
                            ->content(function (?Client $record): string {
                                if (! $record || ! $record->phone) {
                                    return '—';
                                }
                                $attempts = \App\Services\Auth\LoginThrottleService::attempts('client', $record->phone);
                                $isLocked = \App\Services\Auth\LoginThrottleService::isLocked('client', $record->phone);
                                $seconds = \App\Services\Auth\LoginThrottleService::availableIn('client', $record->phone);

                                if ($isLocked) {
                                    return "⚠️ BLOQUÉ : {$attempts} tentative(s) échouée(s) — Déblocage automatique dans {$seconds}s.";
                                }
                                if ($attempts > 0) {
                                    return "⚠️ {$attempts} tentative(s) échouée(s) enregistrée(s). Compte actif.";
                                }
                                return "✓ Normal : 0 tentative échouée, accès totalement libre.";
                            }),
                    ])
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar_url')
                    ->label('')
                    ->circular()
                    ->size(40)
                    ->defaultImageUrl(fn (?Client $record): ?string => \App\Support\AvatarHelper::forClient($record)),

                Tables\Columns\TextColumn::make('full_name')
                    ->label('Client')
                    ->description(fn (Client $record): ?string => $record->email)
                    ->searchable(['first_name', 'last_name', 'email'])
                    ->sortable(['first_name'])
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Téléphone')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Numéro copié')
                    ->description(fn (Client $record): string => $record->phone_verified_at ? '✓ Vérifié' : '⚠ Non vérifié')
                    ->icon(fn (Client $record): ?string => $record->phone_verified_at ? 'heroicon-m-check-badge' : 'heroicon-m-exclamation-triangle')
                    ->iconColor(fn (Client $record): string => $record->phone_verified_at ? 'success' : 'warning'),

                Tables\Columns\TextColumn::make('city')
                    ->label('Localisation')
                    ->formatStateUsing(fn ($state, Client $record) => $state ? "{$state}" . ($record->country ? ", {$record->country}" : '') : '—')
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('loyalty_cards_count')
                    ->label('Cartes')
                    ->counts('loyaltyCards')
                    ->badge()
                    ->color('primary')
                    ->sortable()
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('oauth_provider')
                    ->label('Connexion')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? ucfirst($state) : 'Classique')
                    ->color(fn ($state) => $state ? 'info' : 'gray')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('auth_status')
                    ->label('Accès')
                    ->getStateUsing(fn (Client $record): bool => ! \App\Services\Auth\LoginThrottleService::isLocked('client', $record->phone))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-open')
                    ->falseIcon('heroicon-s-lock-closed')
                    ->trueColor('gray')
                    ->falseColor('danger')
                    ->tooltip(function (Client $record): string {
                        $attempts = \App\Services\Auth\LoginThrottleService::attempts('client', $record->phone);
                        $isLocked = \App\Services\Auth\LoginThrottleService::isLocked('client', $record->phone);
                        $seconds = \App\Services\Auth\LoginThrottleService::availableIn('client', $record->phone);

                        if ($isLocked) {
                            return "Compte bloqué ({$attempts} tentatives) — Déblocage auto dans {$seconds}s";
                        }
                        if ($attempts > 0) {
                            return "{$attempts} tentative(s) échouée(s) enregistrée(s)";
                        }
                        return 'Accès normal (aucun verrou)';
                    })
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Inscrit')
                    ->since()
                    ->sortable()
                    ->color('gray'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('phone_verified')
                    ->label('Téléphone Vérifié')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('phone_verified_at'),
                        false: fn ($query) => $query->whereNull('phone_verified_at'),
                    ),

                Tables\Filters\SelectFilter::make('oauth_provider')
                    ->label('Méthode de connexion')
                    ->options([
                        'google' => 'Google',
                        'apple' => 'Apple',
                    ])
                    ->query(function ($query, array $data) {
                        if ($data['value'] === null) return $query;
                        return $query->where('oauth_provider', $data['value']);
                    }),

                Tables\Filters\Filter::make('has_cards')
                    ->label('Avec carte(s) de fidélité')
                    ->query(fn ($query) => $query->has('loyaltyCards'))
                    ->toggle(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),

                Tables\Actions\Action::make('unlockLogin')
                    ->label('Débloquer Connexion')
                    ->icon('heroicon-o-lock-open')
                    ->color(fn (Client $record): string => \App\Services\Auth\LoginThrottleService::isLocked('client', $record->phone) ? 'danger' : 'success')
                    ->visible(fn (Client $record): bool => (bool) auth()->user()?->isSuperAdmin())
                    ->requiresConfirmation()
                    ->modalHeading(fn (Client $record): string => "Débloquer la connexion : {$record->full_name}")
                    ->modalDescription(function (Client $record): string {
                        $attempts = \App\Services\Auth\LoginThrottleService::attempts('client', $record->phone);
                        $isLocked = \App\Services\Auth\LoginThrottleService::isLocked('client', $record->phone);
                        $seconds = \App\Services\Auth\LoginThrottleService::availableIn('client', $record->phone);

                        if ($isLocked) {
                            return "Ce compte a atteint {$attempts} tentatives de connexion infructueuses et est bloqué pour encore {$seconds} secondes. Voulez-vous réinitialiser le verrou et débloquer l'accès immédiatement ?";
                        }
                        if ($attempts > 0) {
                            return "Ce compte compte actuellement {$attempts} tentative(s) échouée(s). Voulez-vous réinitialiser le compteur à zéro ?";
                        }
                        return "Ce compte n'est pas bloqué (0 tentative échouée enregistrée). Souhaitez-vous forcer la réinitialisation des verrous de connexion ?";
                    })
                    ->modalSubmitActionLabel('Débloquer le compte')
                    ->action(function (Client $record): void {
                        \App\Services\Auth\LoginThrottleService::unlock('client', (string) $record->phone);

                        \Illuminate\Support\Facades\Log::info('CLIENT_LOGIN_UNLOCKED_BY_ADMIN', [
                            'admin_id' => auth()->id(),
                            'admin_email' => auth()->user()?->email,
                            'client_id' => $record->id,
                            'client_phone' => $record->phone,
                            'timestamp' => now()->toIso8601String(),
                        ]);

                        \Filament\Notifications\Notification::make()
                            ->success()
                            ->title('Connexion débloquée')
                            ->body("Les tentatives de connexion pour {$record->full_name} ({$record->phone}) ont été réinitialisées avec succès.")
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),

                    Tables\Actions\BulkAction::make('unlockLoginBulk')
                        ->label('Débloquer la connexion')
                        ->icon('heroicon-o-lock-open')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Débloquer la connexion des comptes sélectionnés')
                        ->modalDescription('Toutes les restrictions de tentatives de connexion (rate limiter) seront réinitialisées pour les comptes sélectionnés.')
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records): void {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->phone) {
                                    \App\Services\Auth\LoginThrottleService::unlock('client', $record->phone);
                                    $count++;
                                }
                            }

                            \Filament\Notifications\Notification::make()
                                ->success()
                                ->title('Comptes débloqués')
                                ->body("{$count} compte(s) client(s) ont été débloqués avec succès.")
                                ->send();
                        }),
                ]),
            ])
            ->emptyStateHeading('Aucun client enregistré')
            ->emptyStateDescription('Les utilisateurs de l\'application mobile apparaîtront ici.')
            ->emptyStateIcon('heroicon-o-users');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'view' => Pages\ViewClient::route('/{record}'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }
}
