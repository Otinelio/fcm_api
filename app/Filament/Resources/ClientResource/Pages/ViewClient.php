<?php

namespace App\Filament\Resources\ClientResource\Pages;

use App\Filament\Resources\ClientResource;
use Filament\Actions;
use Filament\Infolists\Components;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->icon('heroicon-o-pencil-square'),

            Actions\Action::make('unlockLogin')
                ->label('Débloquer Connexion')
                ->icon('heroicon-o-lock-open')
                ->color(fn (): string => \App\Services\Auth\LoginThrottleService::isLocked('client', $this->getRecord()->phone) ? 'danger' : 'success')
                ->visible(fn (): bool => (bool) auth()->user()?->isSuperAdmin())
                ->requiresConfirmation()
                ->modalHeading(fn (): string => "Débloquer la connexion : {$this->getRecord()->full_name}")
                ->modalDescription(function (): string {
                    $phone = $this->getRecord()->phone;
                    $attempts = \App\Services\Auth\LoginThrottleService::attempts('client', $phone);
                    $isLocked = \App\Services\Auth\LoginThrottleService::isLocked('client', $phone);
                    $seconds = \App\Services\Auth\LoginThrottleService::availableIn('client', $phone);

                    if ($isLocked) {
                        return "Ce compte a atteint {$attempts} tentatives de connexion infructueuses et est bloqué pour encore {$seconds} secondes. Voulez-vous réinitialiser le verrou et débloquer l'accès immédiatement ?";
                    }
                    if ($attempts > 0) {
                        return "Ce compte enregistre actuellement {$attempts} tentative(s) échouée(s). Voulez-vous réinitialiser le compteur à zéro ?";
                    }
                    return "Ce compte n'est pas bloqué (0 tentative échouée enregistrée). Souhaitez-vous forcer la réinitialisation des verrous de connexion ?";
                })
                ->modalSubmitActionLabel('Débloquer le compte')
                ->action(function (): void {
                    $client = $this->getRecord();
                    \App\Services\Auth\LoginThrottleService::unlock('client', (string) $client->phone);

                    \Illuminate\Support\Facades\Log::info('CLIENT_LOGIN_UNLOCKED_BY_ADMIN', [
                        'admin_id' => auth()->id(),
                        'admin_email' => auth()->user()?->email,
                        'client_id' => $client->id,
                        'client_phone' => $client->phone,
                        'timestamp' => now()->toIso8601String(),
                    ]);

                    \Filament\Notifications\Notification::make()
                        ->success()
                        ->title('Connexion débloquée')
                        ->body("Les tentatives de connexion pour {$client->full_name} ({$client->phone}) ont été réinitialisées avec succès.")
                        ->send();
                }),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Components\ViewEntry::make('client_profile')
                    ->label('')
                    ->view('filament.resources.client.components.client-profile-view')
                    ->columnSpanFull(),
            ]);
    }
}
