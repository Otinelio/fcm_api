<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Initialisation du SDK FedaPay une seule fois au démarrage de l'app.
        // C'est ce qui permet ensuite d'appeler \FedaPay\Transaction::create(...)
        // n'importe où dans le code sans réinitialiser la clé à chaque fois.
        \FedaPay\FedaPay::setApiKey(config('fedapay.secret_key'));
        \FedaPay\FedaPay::setEnvironment(config('fedapay.environment'));
    }
}
