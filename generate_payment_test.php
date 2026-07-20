<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use App\Http\Controllers\PaymentController;

// On crée/récupère un utilisateur de test et un plan de test
$user = User::firstOrCreate(
    ['email' => 'test@example.com'],
    ['name' => 'Test User', 'password' => bcrypt('password')]
);

$plan = SubscriptionPlan::firstOrCreate(
    ['name' => 'Plan Test', 'slug' => 'plan-test'],
    ['price_xof' => 1000, 'duration_days' => 30]
);

// On simule une requête authentifiée
$request = Request::create('/api/subscriptions/'.$plan->id.'/pay', 'POST');
$request->setUserResolver(function () use ($user) {
    return $user;
});

// On appelle le contrôleur
$controller = new PaymentController();
$response = $controller->initSubscriptionPayment($request, $plan);

echo "\n========================================\n";
echo "🔗 LIEN DE PAIEMENT GÉNÉRÉ :\n";
echo json_decode($response->getContent())->payment_url . "\n";
echo "========================================\n\n";
