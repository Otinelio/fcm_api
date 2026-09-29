<?php
    /** @var \App\Models\Restaurant $record */
    $record = $getRecord();
    $history = $record->monthly_consumption;
    $max = max(1, max(array_column($history, 'count')));
?>

<div class="p-4 rounded-xl bg-white dark:bg-gray-900 shadow-sm border border-gray-200 dark:border-gray-700">
    <div class="flex items-end gap-3 h-48">
        <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php $__currentLoopData = $history; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $entry): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
            <?php
                $heightPercent = $max > 0 ? ($entry['count'] / $max) * 100 : 0;
                $barColor = match (true) {
                    $entry['count'] === 0 => 'bg-gray-200 dark:bg-gray-700',
                    $entry['count'] > $max * 0.7 => 'bg-red-400 dark:bg-red-500',
                    $entry['count'] > $max * 0.4 => 'bg-amber-400 dark:bg-amber-500',
                    default => 'bg-emerald-400 dark:bg-emerald-500',
                };
            ?>
            <div class="flex-1 flex flex-col items-center gap-2">
                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">
                    <?php echo e($entry['count']); ?>

                </span>
                <div class="w-full rounded-t-lg transition-all duration-700 ease-out <?php echo e($barColor); ?>"
                     style="height: <?php echo e(max(4, $heightPercent)); ?>%; min-height: 4px;">
                </div>
                <span class="text-[10px] text-gray-500 dark:text-gray-400 whitespace-nowrap">
                    <?php echo e($entry['month']); ?>

                </span>
            </div>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    </div>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if(collect($history)->sum('count') === 0): ?>
        <p class="text-center text-sm text-gray-400 dark:text-gray-500 mt-4">
            Aucune consommation enregistrée sur les 6 derniers mois.
        </p>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /home/othnelio/fcm/restaurant-loyalty-api/resources/views/filament/resources/restaurant/components/consumption-chart.blade.php ENDPATH**/ ?>