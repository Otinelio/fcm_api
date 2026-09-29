<?php
    /** @var \App\Models\LoyaltyCard $record */
    $record = $getRecord();
    $percent = $record->percent;
    $goal = $record->goal;
    $current = (int) ($record->progress['stamps_current'] ?? 0);
    $type = $record->loyaltyProgram?->type ?? 'stamp';
    $level = $record->level;

    $barColor = match (true) {
        $percent >= 90 => 'bg-emerald-500',
        $percent >= 60 => 'bg-blue-500',
        $percent >= 30 => 'bg-amber-500',
        default => 'bg-indigo-500',
    };
?>

<div class="min-w-[100px]">
    <div class="flex items-center justify-between mb-0.5">
        <span class="text-[11px] font-bold text-slate-700 dark:text-slate-300">
            <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($type === 'cashback'): ?>
                <?php echo e(number_format((float) $record->cashback_available_fcfa, 0, ',', ' ')); ?> F
            <?php elseif($goal): ?>
                <?php echo e($current); ?>/<?php echo e($goal); ?>

            <?php else: ?>
                <?php echo e($percent); ?>%
            <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
        </span>
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($level && $level['name']): ?>
            <span class="text-[10px] font-bold text-amber-600 dark:text-amber-400"><?php echo e($level['name']); ?></span>
        <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>
    <div class="w-full bg-slate-100 dark:bg-slate-700 rounded-full h-1.5 overflow-hidden">
        <div class="<?php echo e($barColor); ?> h-1.5 rounded-full transition-all" style="width: <?php echo e(min(100, $percent)); ?>%;"></div>
    </div>
</div>
<?php /**PATH /home/othnelio/fcm/restaurant-loyalty-api/resources/views/filament/resources/loyalty-card/components/progress-cell.blade.php ENDPATH**/ ?>