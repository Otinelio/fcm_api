<?php

namespace App\Filament\Resources\RestaurantResource\Pages;

use App\Filament\Resources\RestaurantResource;
use App\Models\Restaurant;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewRestaurant extends ViewRecord
{
    protected static string $resource = RestaurantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->icon('heroicon-o-pencil-square'),

            Actions\Action::make('unlockLogin')
                ->label('Débloquer Connexion')
                ->icon('heroicon-o-lock-open')
                ->color(fn (): string => \App\Services\Auth\LoginThrottleService::isLocked('restaurant', $this->getRecord()->email) ? 'danger' : 'success')
                ->visible(fn (): bool => (bool) auth()->user()?->isSuperAdmin())
                ->requiresConfirmation()
                ->modalHeading(fn (): string => "Débloquer la connexion : {$this->getRecord()->name}")
                ->modalDescription(function (): string {
                    $email = $this->getRecord()->email;
                    $attempts = \App\Services\Auth\LoginThrottleService::attempts('restaurant', $email);
                    $isLocked = \App\Services\Auth\LoginThrottleService::isLocked('restaurant', $email);
                    $seconds = \App\Services\Auth\LoginThrottleService::availableIn('restaurant', $email);

                    if ($isLocked) {
                        return "Ce compte marchand a atteint {$attempts} tentatives de connexion infructueuses et est bloqué pour encore {$seconds} secondes. Voulez-vous réinitialiser le verrou et débloquer l'accès immédiatement ?";
                    }
                    if ($attempts > 0) {
                        return "Ce compte compte actuellement {$attempts} tentative(s) échouée(s). Voulez-vous réinitialiser le compteur à zéro ?";
                    }
                    return "Ce compte n'est pas bloqué (0 tentative échouée enregistrée). Souhaitez-vous forcer la réinitialisation des verrous de connexion ?";
                })
                ->modalSubmitActionLabel('Débloquer le compte')
                ->action(function (): void {
                    $restaurant = $this->getRecord();
                    \App\Services\Auth\LoginThrottleService::unlock('restaurant', (string) $restaurant->email);

                    \Illuminate\Support\Facades\Log::info('RESTAURANT_LOGIN_UNLOCKED_BY_ADMIN', [
                        'admin_id' => auth()->id(),
                        'admin_email' => auth()->user()?->email,
                        'restaurant_id' => $restaurant->id,
                        'restaurant_email' => $restaurant->email,
                        'restaurant_name' => $restaurant->name,
                        'timestamp' => now()->toIso8601String(),
                    ]);

                    Notification::make()
                        ->success()
                        ->title('Connexion marchand débloquée')
                        ->body("Les tentatives de connexion pour « {$restaurant->name} » ({$restaurant->email}) ont été réinitialisées avec succès.")
                        ->send();
                }),

            Actions\Action::make('suspendFcm')
                ->label('Suspendre FCM')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->visible(fn (): bool => ! $this->getRecord()->isFcmSuspended())
                ->requiresConfirmation()
                ->modalHeading('Suspendre les notifications Push FCM')
                ->modalDescription('Êtes-vous sûr de vouloir suspendre le service FCM pour cet établissement ? Les envois et programmations de campagnes seront immédiatement bloqués.')
                ->modalSubmitActionLabel('Confirmer la suspension')
                ->form([
                    Forms\Components\Textarea::make('reason')
                        ->label('Motif de la suspension (optionnel)')
                        ->placeholder('Ex: Non-respect des règles éditoriales, fréquence excessive d\'envois, litige commercial...')
                        ->rows(3),
                ])
                ->action(function (array $data, Restaurant $record): void {
                    $record->suspendFcm($data['reason'] ?? null);

                    Notification::make()
                        ->warning()
                        ->title('Notifications FCM suspendues')
                        ->body("Le service Push FCM de « {$record->name} » est désormais suspendu.")
                        ->send();
                }),

            Actions\Action::make('reactivateFcm')
                ->label('Réactiver FCM')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->isFcmSuspended())
                ->requiresConfirmation()
                ->modalHeading('Réactiver les notifications Push FCM')
                ->modalDescription('Confirmez-vous la réactivation du service Push FCM pour cet établissement ? Le marchand pourra à nouveau envoyer et programmer des notifications.')
                ->modalSubmitActionLabel('Confirmer la réactivation')
                ->action(function (Restaurant $record): void {
                    $record->reactivateFcm();

                    Notification::make()
                        ->success()
                        ->title('Notifications FCM réactivées')
                        ->body("Le service Push FCM de « {$record->name} » a été réactivé avec succès.")
                        ->send();
                }),

            Actions\Action::make('adjustCredits')
                ->label('Ajuster le quota')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->form([
                    Forms\Components\Select::make('operation')
                        ->label('Opération')
                        ->options([
                            'add' => '➕ Ajouter des crédits',
                            'set' => '🔄 Définir le solde',
                            'subtract' => '➖ Retirer des crédits',
                        ])
                        ->default('add')
                        ->required()
                        ->live(),

                    Forms\Components\TextInput::make('amount')
                        ->label('Montant')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->helperText(fn (Forms\Get $get) => match ($get('operation')) {
                            'add' => 'Nombre de crédits à ajouter au solde actuel.',
                            'set' => 'Nouveau solde de crédits (remplace l\'actuel).',
                            'subtract' => 'Nombre de crédits à retirer du solde actuel.',
                            default => '',
                        }),

                    Forms\Components\Textarea::make('reason')
                        ->label('Motif (optionnel)')
                        ->placeholder('Ex: Recharge suite achat pack 500, Correction solde...')
                        ->rows(2),
                ])
                ->action(function (array $data, Restaurant $record): void {
                    $old = $record->sms_credits;
                    $amount = (int) $data['amount'];

                    $new = match ($data['operation']) {
                        'add' => $old + $amount,
                        'set' => $amount,
                        'subtract' => max(0, $old - $amount),
                        default => $old,
                    };

                    $record->update(['sms_credits' => $new]);

                    $opLabel = match ($data['operation']) {
                        'add' => "ajouté {$amount} crédits",
                        'set' => "défini le solde à {$new}",
                        'subtract' => "retiré {$amount} crédits",
                        default => 'modifié',
                    };

                    Notification::make()
                        ->success()
                        ->title('Quota mis à jour')
                        ->body("Crédit {$opLabel}. Ancien solde : {$old} → Nouveau solde : {$new}")
                        ->send();
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                // ── Synthèse établissement ──
                Components\Section::make('Établissement')
                    ->icon('heroicon-o-building-storefront')
                    ->columns(4)
                    ->schema([
                        Components\TextEntry::make('name')
                            ->label('Nom')
                            ->weight('bold'),

                        Components\TextEntry::make('category')
                            ->label('Catégorie')
                            ->default('—'),

                        Components\TextEntry::make('email')
                            ->label('Contact')
                            ->copyable(),

                        Components\TextEntry::make('plan.name')
                            ->label('Formule')
                            ->badge()
                            ->color('primary')
                            ->default('Gratuit'),
                    ]),

                // ── Section visuelle du quota ──
                Components\Section::make('Quota de notifications FCM')
                    ->icon('heroicon-o-bell-alert')
                    ->description('Consommation et disponibilité des crédits de notification push.')
                    ->schema([
                        Components\ViewEntry::make('quota_dashboard')
                            ->label('')
                            ->view('filament.resources.restaurant.components.quota-dashboard')
                            ->columnSpanFull(),
                    ]),

                // ── Historique mensuel ──
                Components\Section::make('Historique de consommation')
                    ->icon('heroicon-o-chart-bar')
                    ->description('Crédits consommés par mois sur les 6 derniers mois.')
                    ->collapsible()
                    ->schema([
                        Components\ViewEntry::make('consumption_chart')
                            ->label('')
                            ->view('filament.resources.restaurant.components.consumption-chart')
                            ->columnSpanFull(),
                    ]),

                // ── Dernières campagnes ──
                Components\Section::make('Dernières campagnes')
                    ->icon('heroicon-o-paper-airplane')
                    ->description('Les 10 campagnes les plus récentes et leur consommation de crédits.')
                    ->collapsible()
                    ->schema([
                        Components\ViewEntry::make('recent_campaigns')
                            ->label('')
                            ->view('filament.resources.restaurant.components.recent-campaigns')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
