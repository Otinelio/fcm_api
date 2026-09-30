<?php

namespace App\Console\Commands;

use App\Services\Auth\LoginThrottleService;
use Illuminate\Console\Command;

class UnlockAuthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'auth:unlock 
                            {type : Le type de compte (client, restaurant, staff)} 
                            {identifier : Le numéro de téléphone (client) ou adresse email (restaurant/staff)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Débloque un compte dont la connexion a été verrouillée suite à de trop nombreuses tentatives (Rate Limiting).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = strtolower($this->argument('type'));
        $identifier = $this->argument('identifier');

        if (! in_array($type, ['client', 'restaurant', 'staff'])) {
            $this->error("Type invalide: '{$type}'. Types autorisés: client, restaurant, staff.");
            return self::FAILURE;
        }

        $attempts = LoginThrottleService::attempts($type, $identifier);
        $isLocked = LoginThrottleService::isLocked($type, $identifier);

        $this->info("Vérification pour {$type} [{$identifier}]...");
        $this->line("- Tentatives enregistrées : {$attempts}");
        $this->line("- Statut verrouillé : " . ($isLocked ? 'OUI' : 'NON'));

        LoginThrottleService::unlock($type, $identifier);

        $this->newLine();
        $this->info("✓ Compte [{$identifier}] débloqué avec succès. Toutes les restrictions de rate limiting ont été purgées.");

        return self::SUCCESS;
    }
}
