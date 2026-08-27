<?php

namespace App\Providers;

use FedaPay\FedaPay;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        // Rate limiters nommés (L11+) — utilisés via `throttle:<name>` dans
        // routes/api.php.
        // Endpoints marchands sensibles au brute-force (lookup de carte par
        // code, lookup de récompense par token) : quota par utilisateur
        // authentifié (Sanctum — `$request->user()` est déjà résolu au moment
        // où `throttle` s'exécute, le middleware étant après `auth:sanctum`),
        // repli sur l'IP si non identifié.
        RateLimiter::for('merchant-lookup', function (Request $request) {
            return Limit::perMinute(30)->by($request->user()?->id ?: $request->ip());
        });

        // Initialisation du SDK FedaPay une seule fois au démarrage de l'app.
        // C'est ce qui permet ensuite d'appeler \FedaPay\Transaction::create(...)
        // n'importe où dans le code sans réinitialiser la clé à chaque fois.
        FedaPay::setApiKey(config('fedapay.secret_key'));
        FedaPay::setEnvironment(config('fedapay.environment'));
    }
}
