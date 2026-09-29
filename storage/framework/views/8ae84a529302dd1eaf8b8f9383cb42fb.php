<?php
    /** @var \App\Models\LoyaltyCard $record */
    $record = $getRecord();
    $client = $record->client;
    $restaurant = $record->restaurant;
    $program = $record->loyaltyProgram;

    $clientName = $client?->full_name ?: 'Client inconnu';
    $clientPhone = $client?->phone;
    $clientAvatar = $client?->avatar_url ?? \App\Support\AvatarHelper::forClient($client);
    $restaurantName = $restaurant?->name ?: 'Établissement';
    $restaurantLogo = $restaurant?->logo_url ?? \App\Support\AvatarHelper::forRestaurant($restaurant);
    $programName = $program?->name ?: 'Programme de fidélité';
    $programType = $program?->type ?? 'stamp';

    $progress = $record->progress ?? [];
    $stampsCurrent = (int) ($progress['stamps_current'] ?? 0);
    $goal = $record->goal;
    $percent = $record->percent;
    $level = $record->level;
    $tiers = $record->tiers;
    $nextReward = $record->next_reward;

    $statusColors = [
        'active' => ['bg' => 'bg-emerald-100 dark:bg-emerald-900/40', 'text' => 'text-emerald-700 dark:text-emerald-300', 'dot' => 'bg-emerald-500', 'label' => 'Active'],
        'completed' => ['bg' => 'bg-blue-100 dark:bg-blue-900/40', 'text' => 'text-blue-700 dark:text-blue-300', 'dot' => 'bg-blue-500', 'label' => 'Complétée'],
        'expired' => ['bg' => 'bg-red-100 dark:bg-red-900/40', 'text' => 'text-red-700 dark:text-red-300', 'dot' => 'bg-red-500', 'label' => 'Expirée'],
    ];
    $statusInfo = $statusColors[$record->status] ?? $statusColors['active'];

    $typeLabels = [
        'stamp' => ['label' => 'Tampons', 'icon' => '🎫', 'unit' => 'tampons'],
        'points' => ['label' => 'Points', 'icon' => '⭐', 'unit' => 'points'],
        'cashback' => ['label' => 'Cashback', 'icon' => '💰', 'unit' => 'FCFA'],
        'vip' => ['label' => 'VIP', 'icon' => '👑', 'unit' => 'points'],
    ];
    $typeInfo = $typeLabels[$programType] ?? $typeLabels['stamp'];

    // Rewards (dernières)
    $rewards = \App\Models\LoyaltyReward::where('loyalty_card_id', $record->id)
        ->orderByDesc('unlocked_at')
        ->limit(10)
        ->get();

    // Transactions (dernières)
    $transactions = \App\Models\LoyaltyTransaction::where('loyalty_card_id', $record->id)
        ->with('staffUser')
        ->orderByDesc('created_at')
        ->limit(15)
        ->get();

    // Ring color
    $ringColor = match (true) {
        $percent >= 90 => '#10b981',
        $percent >= 60 => '#3b82f6',
        $percent >= 30 => '#f59e0b',
        default => '#6366f1',
    };
?>

<div class="space-y-6">

    
    <div class="rounded-2xl overflow-hidden shadow-lg border border-slate-200 dark:border-slate-800">
        
        <div class="relative bg-gradient-to-br from-indigo-600 via-violet-600 to-purple-700 px-6 py-8 text-white">
            <div class="absolute top-0 right-0 w-64 h-64 opacity-10">
                <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg"><path fill="white" d="M47.9,-58.7C59.5,-48.7,64.5,-31.2,67.2,-13.6C69.9,4,70.3,21.7,62.6,35.1C54.8,48.5,38.8,57.6,22,62.3C5.1,67,-12.7,67.3,-28.5,61.2C-44.3,55.1,-58.2,42.7,-64.7,27.3C-71.2,11.9,-70.4,-6.5,-63.5,-21.3C-56.5,-36.2,-43.3,-47.5,-29.5,-57C-15.7,-66.4,-1.3,-73.9,11.3,-73.2C23.9,-72.5,36.3,-68.7,47.9,-58.7Z" transform="translate(100 100)" /></svg>
            </div>

            <div class="flex items-start justify-between relative z-10">
                <div class="flex items-center gap-4">
                    <img src="<?php echo e($restaurantLogo); ?>" alt="<?php echo e($restaurantName); ?>" class="w-14 h-14 rounded-xl object-cover border-2 border-white/30 shadow-md" />
                    <div>
                        <div class="text-lg font-black tracking-tight"><?php echo e($restaurantName); ?></div>
                        <div class="text-sm text-white/70 font-medium"><?php echo e($programName); ?></div>
                    </div>
                </div>
                <div class="flex flex-col items-end gap-2">
                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold bg-white/20 backdrop-blur-sm">
                        <span class="w-2 h-2 rounded-full <?php echo e($statusInfo['dot']); ?> animate-pulse"></span>
                        <?php echo e($statusInfo['label']); ?>

                    </span>
                    <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-white/15 backdrop-blur-sm">
                        <?php echo e($typeInfo['icon']); ?> <?php echo e($typeInfo['label']); ?>

                    </span>
                </div>
            </div>

            
            <div class="mt-6 flex items-center gap-3 relative z-10">
                <img src="<?php echo e($clientAvatar); ?>" alt="<?php echo e($clientName); ?>" class="w-10 h-10 rounded-full object-cover border-2 border-white/40" />
                <div>
                    <div class="text-sm font-bold"><?php echo e($clientName); ?></div>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($clientPhone): ?>
                        <div class="text-xs text-white/60"><?php echo e($clientPhone); ?></div>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            </div>

            
            <div class="mt-4 flex items-center justify-between relative z-10">
                <div class="font-mono text-lg tracking-[0.3em] font-bold text-white/90"><?php echo e($record->card_code); ?></div>
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($level && $level['name']): ?>
                    <span class="px-3 py-1 rounded-full text-xs font-extrabold bg-amber-400/30 text-amber-100 border border-amber-300/30 backdrop-blur-sm">
                        <?php echo e($level['name']); ?>

                    </span>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>

        
        <div class="bg-white dark:bg-slate-900 px-6 py-5">
            <div class="flex items-center justify-between mb-2">
                <span class="text-xs font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Progression</span>
                <span class="text-sm font-extrabold text-slate-900 dark:text-white">
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($programType === 'cashback'): ?>
                        <?php echo e(number_format((float) $record->cashback_available_fcfa, 0, ',', ' ')); ?> FCFA
                    <?php elseif($goal): ?>
                        <?php echo e($stampsCurrent); ?> / <?php echo e($goal); ?> <?php echo e($typeInfo['unit']); ?>

                    <?php else: ?>
                        <?php echo e($percent); ?>%
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </span>
            </div>
            <div class="w-full bg-slate-100 dark:bg-slate-800 rounded-full h-3 overflow-hidden">
                <div class="h-3 rounded-full transition-all duration-700" style="width: <?php echo e(min(100, $percent)); ?>%; background: <?php echo e($ringColor); ?>;"></div>
            </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($nextReward): ?>
                <div class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                    🎁 Prochaine récompense : <span class="font-semibold text-slate-700 dark:text-slate-300"><?php echo e($nextReward['reward_description'] ?? 'Surprise'); ?></span>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(isset($nextReward['goal'])): ?>
                        — à <?php echo e($nextReward['goal']); ?> <?php echo e($typeInfo['unit']); ?>

                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </div>

    
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        
        <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
            <div class="text-xs font-bold uppercase tracking-wider text-violet-600 dark:text-violet-400">Cycles</div>
            <div class="mt-2 text-2xl font-extrabold text-slate-900 dark:text-white"><?php echo e($record->cycles_completed ?? 0); ?></div>
            <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">cycles complétés</div>
        </div>

        
        <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
            <div class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Cashback</div>
            <div class="mt-2 text-2xl font-extrabold text-slate-900 dark:text-white"><?php echo e(number_format((float) $record->cashback_available_fcfa, 0, ',', ' ')); ?></div>
            <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">FCFA disponibles</div>
        </div>

        
        <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
            <div class="text-xs font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">Récompenses</div>
            <div class="mt-2 text-2xl font-extrabold text-slate-900 dark:text-white"><?php echo e($rewards->count()); ?></div>
            <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">débloquées au total</div>
        </div>

        
        <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
            <div class="text-xs font-bold uppercase tracking-wider text-sky-600 dark:text-sky-400">Dernier passage</div>
            <div class="mt-2 text-lg font-extrabold text-slate-900 dark:text-white">
                <?php echo e($record->last_activity_at ? $record->last_activity_at->format('d/m/Y') : '—'); ?>

            </div>
            <div class="text-[11px] text-slate-500 dark:text-slate-400 mt-0.5">
                <?php echo e($record->last_activity_at ? $record->last_activity_at->diffForHumans() : 'Aucune activité'); ?>

            </div>
        </div>
    </div>

    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(count($tiers) > 0): ?>
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-xs">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-4">📊 Parcours de niveaux</h3>
            <div class="flex items-center gap-2 overflow-x-auto pb-2">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $tiers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $i => $tier): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $isReached = $tier['reached'] ?? false;
                        $isCurrent = ($level && $level['name'] === ($tier['level_name'] ?? null));
                    ?>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <div class="flex flex-col items-center min-w-[90px] p-3 rounded-xl border-2 transition-all
                            <?php echo e($isCurrent ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-950/40 shadow-md' : ($isReached ? 'border-emerald-300 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-950/20' : 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50')); ?>">
                            <span class="text-lg"><?php echo e($isReached ? '✅' : ($isCurrent ? '🔥' : '🔒')); ?></span>
                            <span class="text-xs font-bold mt-1 <?php echo e($isCurrent ? 'text-indigo-700 dark:text-indigo-300' : ($isReached ? 'text-emerald-700 dark:text-emerald-300' : 'text-slate-500 dark:text-slate-400')); ?>">
                                <?php echo e($tier['level_name'] ?? "Palier ".($i+1)); ?>

                            </span>
                            <span class="text-[10px] text-slate-400 dark:text-slate-500"><?php echo e($tier['goal'] ?? '?'); ?> <?php echo e($typeInfo['unit']); ?></span>
                        </div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($i < count($tiers) - 1): ?>
                            <div class="w-6 h-0.5 <?php echo e($isReached ? 'bg-emerald-400' : 'bg-slate-200 dark:bg-slate-700'); ?>"></div>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($rewards->count() > 0): ?>
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-xs">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-4">🎁 Récompenses</h3>
            <div class="space-y-3">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $rewards; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $reward): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $rStatus = $reward->is_expired ? 'expired' : $reward->status;
                        $rColors = match ($rStatus) {
                            'available' => 'border-emerald-200 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-950/20',
                            'used' => 'border-blue-200 dark:border-blue-800 bg-blue-50/50 dark:bg-blue-950/20',
                            'canceled' => 'border-slate-200 dark:border-slate-700 bg-slate-50 dark:bg-slate-800/50 opacity-60',
                            'expired' => 'border-red-200 dark:border-red-800 bg-red-50/50 dark:bg-red-950/20 opacity-70',
                            default => 'border-slate-200 dark:border-slate-800',
                        };
                        $rLabel = match ($rStatus) {
                            'available' => '🟢 Disponible',
                            'used' => '✅ Utilisée',
                            'canceled' => '❌ Annulée',
                            'expired' => '⏰ Expirée',
                            default => $rStatus,
                        };
                    ?>
                    <div class="flex items-center justify-between p-3 rounded-xl border <?php echo e($rColors); ?>">
                        <div class="flex items-center gap-3">
                            <span class="text-xl"><?php echo e($reward->is_surprise ? '🎊' : '🎁'); ?></span>
                            <div>
                                <div class="text-sm font-bold text-slate-900 dark:text-white"><?php echo e($reward->title ?: 'Récompense fidélité'); ?></div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400">
                                    Débloquée le <?php echo e($reward->unlocked_at?->format('d/m/Y')); ?>

                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($reward->expires_at): ?>
                                        · Expire le <?php echo e($reward->expires_at->format('d/m/Y')); ?>

                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-full
                            <?php echo e(match ($rStatus) {
                                'available' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300',
                                'used' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300',
                                'canceled' => 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400',
                                'expired' => 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300',
                                default => 'bg-slate-100 text-slate-600',
                            }); ?>">
                            <?php echo e($rLabel); ?>

                        </span>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($transactions->count() > 0): ?>
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-xs">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-4">📋 Dernières activités</h3>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $transactions; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $tx): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <?php
                        $txIcon = match ($tx->type) {
                            'stamp' => '🎫',
                            'cashback_earn' => '💰',
                            'cashback_spend' => '🛒',
                            'bonus', 'referral_bonus' => '🎉',
                            'reset' => '🔄',
                            default => '📌',
                        };
                        $txLabel = match ($tx->type) {
                            'stamp' => 'Tampon validé',
                            'cashback_earn' => 'Cashback gagné',
                            'cashback_spend' => 'Cashback dépensé',
                            'bonus' => 'Bonus',
                            'referral_bonus' => 'Bonus parrainage',
                            'reset' => 'Réinitialisation',
                            default => ucfirst(str_replace('_', ' ', $tx->type)),
                        };
                        $txCanceled = $tx->status === 'canceled';
                    ?>
                    <div class="flex items-center justify-between py-3 <?php echo e($txCanceled ? 'opacity-50' : ''); ?>">
                        <div class="flex items-center gap-3">
                            <span class="text-lg"><?php echo e($txIcon); ?></span>
                            <div>
                                <div class="text-sm font-semibold text-slate-800 dark:text-slate-200 <?php echo e($txCanceled ? 'line-through' : ''); ?>">
                                    <?php echo e($txLabel); ?>

                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tx->value && $tx->value > 0): ?>
                                        <span class="text-xs font-bold text-indigo-600 dark:text-indigo-400">
                                            +<?php echo e($programType === 'cashback' ? number_format((float) $tx->value, 0, ',', ' ').' FCFA' : intval($tx->value)); ?>

                                        </span>
                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                                <div class="text-[11px] text-slate-400 dark:text-slate-500">
                                    <?php echo e($tx->created_at?->format('d/m/Y à H:i')); ?>

                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tx->staffUser): ?>
                                        · par <?php echo e($tx->staffUser->name ?? 'Personnel'); ?>

                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($tx->validation_method): ?>
                                        · <?php echo e($tx->validation_method === 'qr' ? 'QR code' : ucfirst($tx->validation_method)); ?>

                                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($txCanceled): ?>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400">Annulé</span>
                        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    </div>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        </div>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

    
    <details class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 shadow-xs">
        <summary class="px-6 py-4 cursor-pointer text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition-colors select-none">
            🔧 Informations techniques
        </summary>
        <div class="px-6 pb-5 grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
            <div>
                <span class="text-slate-400 block">ID Carte</span>
                <span class="font-mono font-bold text-slate-700 dark:text-slate-300"><?php echo e($record->id); ?></span>
            </div>
            <div>
                <span class="text-slate-400 block">Code Carte</span>
                <span class="font-mono font-bold text-slate-700 dark:text-slate-300"><?php echo e($record->card_code); ?></span>
            </div>
            <div>
                <span class="text-slate-400 block">QR Token</span>
                <span class="font-mono text-[11px] text-slate-500 dark:text-slate-400 break-all"><?php echo e($record->qr_token); ?></span>
            </div>
            <div>
                <span class="text-slate-400 block">Code Parrainage</span>
                <span class="font-mono font-bold text-slate-700 dark:text-slate-300"><?php echo e($record->referral_code); ?></span>
            </div>
            <div>
                <span class="text-slate-400 block">ID Client</span>
                <span class="font-mono text-slate-500 dark:text-slate-400"><?php echo e($record->client_id); ?></span>
            </div>
            <div>
                <span class="text-slate-400 block">ID Établissement</span>
                <span class="font-mono text-slate-500 dark:text-slate-400"><?php echo e($record->restaurant_id); ?></span>
            </div>
            <div>
                <span class="text-slate-400 block">ID Programme</span>
                <span class="font-mono text-slate-500 dark:text-slate-400"><?php echo e($record->loyalty_program_id); ?></span>
            </div>
            <div>
                <span class="text-slate-400 block">Progression brute</span>
                <span class="font-mono text-[11px] text-slate-500 dark:text-slate-400"><?php echo e(json_encode($record->progress)); ?></span>
            </div>
            <div>
                <span class="text-slate-400 block">Créée le</span>
                <span class="text-slate-600 dark:text-slate-300"><?php echo e($record->created_at?->format('d/m/Y H:i:s')); ?></span>
            </div>
            <div>
                <span class="text-slate-400 block">Modifiée le</span>
                <span class="text-slate-600 dark:text-slate-300"><?php echo e($record->updated_at?->format('d/m/Y H:i:s')); ?></span>
            </div>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($record->completed_at): ?>
                <div>
                    <span class="text-slate-400 block">Complétée le</span>
                    <span class="text-slate-600 dark:text-slate-300"><?php echo e($record->completed_at->format('d/m/Y H:i:s')); ?></span>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($record->max_level_name): ?>
                <div>
                    <span class="text-slate-400 block">Niveau max atteint</span>
                    <span class="text-slate-600 dark:text-slate-300"><?php echo e($record->max_level_name); ?> (<?php echo e($record->max_level_reached_at?->format('d/m/Y')); ?>)</span>
                </div>
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </div>
    </details>

</div>
<?php /**PATH /home/othnelio/fcm/restaurant-loyalty-api/resources/views/filament/resources/loyalty-card/components/card-detail-view.blade.php ENDPATH**/ ?>