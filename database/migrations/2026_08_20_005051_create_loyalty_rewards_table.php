<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Une ligne par récompense débloquée (un cycle Tampons/Achats atteint —
     * voir `MerchantDashboardController::grantStampOrPoints`, où elle est
     * insérée dans la même transaction que la ligne `cycle_completed`
     * correspondante). Table distincte de l'ancienne `rewards` (non
     * tenant-scopée, jamais alimentée par le flux réel, laissée telle quelle).
     */
    public function up(): void
    {
        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->id();
            // Pas de cascade sur loyalty_cards, comme loyalty_transactions :
            // garder l'historique même si la carte est supprimée.
            $table->foreignId('loyalty_card_id')->nullable()->constrained('loyalty_cards')->nullOnDelete();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            // Snapshot du libellé au moment du déblocage — jamais résolu
            // dynamiquement depuis `loyalty_programs.config`, qui peut être
            // édité après coup par le marchand.
            $table->string('title');
            $table->string('status')->default('available'); // available, used, canceled
            $table->string('redeem_token')->unique();
            $table->timestamp('unlocked_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->foreignId('used_by_staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->timestamp('canceled_at')->nullable();
            $table->foreignId('canceled_by_staff_user_id')->nullable()->constrained('staff_users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_rewards');
    }
};
