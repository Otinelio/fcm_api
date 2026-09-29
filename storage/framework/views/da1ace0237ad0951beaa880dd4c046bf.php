<?php
    /** @var \App\Models\Restaurant $record */
    $record = $getRecord();
    $total = $record->total_quota;
    $consumed = $record->consumed_quota;
    $remaining = $record->remaining_quota;
    $percent = $record->quota_usage_percent;
    $serviceStatus = $record->notification_service_status;

    // Couleur du ring selon la consommation
    $ringColor = match (true) {
        $percent >= 90 => '#ef4444',  // rouge
        $percent >= 70 => '#f59e0b',  // orange
        $percent >= 50 => '#eab308',  // jaune
        default => '#10b981',          // vert émeraude
    };

    $badgeColors = [
        'success' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800',
        'warning' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300 border border-amber-200 dark:border-amber-800',
        'danger'  => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300 border border-red-200 dark:border-red-800',
        'gray'    => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400 border border-gray-200 dark:border-gray-700',
    ];
?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($record->isFcmSuspended()): ?>
    <div class="mb-6 rounded-2xl bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900/60 p-4">
        <div class="flex items-start gap-3">
            <span class="p-2 rounded-xl bg-red-100 dark:bg-red-900/60 text-red-600 dark:text-red-400">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                </svg>
            </span>
            <div class="flex-1">
                <h4 class="text-sm font-bold text-red-900 dark:text-red-200">Service de notifications Push FCM suspendu</h4>
                <p class="text-xs text-red-700 dark:text-red-300 mt-1">
                    Les envois directs et la programmation de nouvelles campagnes sont bloqués pour cet établissement.
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($record->fcm_suspension_reason): ?>
                        <br><span class="font-semibold text-red-800 dark:text-red-200">Motif :</span> <?php echo e($record->fcm_suspension_reason); ?>

                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($record->fcm_suspended_at): ?>
                        <span class="text-red-500 dark:text-red-400 block mt-0.5">Suspendu le <?php echo e($record->fcm_suspended_at->format('d/m/Y à H:i')); ?></span>
                    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
                </p>
            </div>
        </div>
    </div>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    
    <div class="flex flex-col items-center justify-center p-6 rounded-2xl bg-white dark:bg-gray-900 shadow-sm border border-gray-200 dark:border-gray-800">
        <div class="relative" style="width: 170px; height: 170px;">
            <svg viewBox="0 0 120 120" class="w-full h-full transform -rotate-90">
                
                <circle cx="60" cy="60" r="52" fill="none"
                    stroke="currentColor" class="text-gray-100 dark:text-gray-800"
                    stroke-width="10" />
                
                <circle cx="60" cy="60" r="52" fill="none"
                    stroke="<?php echo e($ringColor); ?>"
                    stroke-width="10"
                    stroke-linecap="round"
                    stroke-dasharray="<?php echo e(2 * M_PI * 52); ?>"
                    stroke-dashoffset="<?php echo e(2 * M_PI * 52 * (1 - min(100, $percent) / 100)); ?>"
                    style="transition: stroke-dashoffset 1s ease-in-out;" />
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-3xl font-extrabold tracking-tight text-gray-900 dark:text-white"><?php echo e(number_format($percent, 0)); ?>%</span>
                <span class="text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mt-0.5">consommé</span>
            </div>
        </div>
        <p class="mt-4 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Taux d'utilisation global</p>
    </div>

    
    <div class="col-span-1 md:col-span-2 grid grid-cols-2 gap-4">
        
        <div class="p-5 rounded-2xl bg-white dark:bg-gray-900 shadow-sm border border-gray-200 dark:border-gray-800 flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-blue-600 dark:text-blue-400">Quota Total</span>
                <span class="p-2 rounded-xl bg-blue-50 dark:bg-blue-950/60 text-blue-600 dark:text-blue-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-4">
                <div class="text-3xl font-extrabold text-gray-900 dark:text-white"><?php echo e(number_format($total)); ?></div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 font-medium">crédits alloués au total</div>
            </div>
        </div>

        
        <div class="p-5 rounded-2xl bg-white dark:bg-gray-900 shadow-sm border border-gray-200 dark:border-gray-800 flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">Consommés</span>
                <span class="p-2 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-amber-600 dark:text-amber-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.362 5.214A8.252 8.252 0 0 1 12 21 8.25 8.25 0 0 1 6.038 7.047 8.287 8.287 0 0 0 9 9.601a8.983 8.983 0 0 1 3.361-6.867 8.21 8.21 0 0 0 3 2.48Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 18a3.75 3.75 0 0 0 .495-7.468 5.99 5.99 0 0 0-1.925 3.547 5.975 5.975 0 0 1-2.133-1.001A3.75 3.75 0 0 0 12 18Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-4">
                <div class="text-3xl font-extrabold text-gray-900 dark:text-white"><?php echo e(number_format($consumed)); ?></div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 font-medium">notifications push envoyées</div>
            </div>
        </div>

        
        <div class="p-5 rounded-2xl bg-white dark:bg-gray-900 shadow-sm border border-gray-200 dark:border-gray-800 flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Restants</span>
                <span class="p-2 rounded-xl bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 dark:text-emerald-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="m9 12 2 2 4-4" />
                    </svg>
                </span>
            </div>
            <div class="mt-4">
                <div class="text-3xl font-extrabold" style="color: <?php echo e($ringColor); ?>"><?php echo e(number_format($remaining)); ?></div>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 font-medium">crédits actuellement disponibles</div>
            </div>
        </div>

        
        <div class="p-5 rounded-2xl bg-white dark:bg-gray-900 shadow-sm border border-gray-200 dark:border-gray-800 flex flex-col justify-between">
            <div class="flex items-center justify-between">
                <span class="text-xs font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">État Service</span>
                <span class="p-2 rounded-xl bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.348 14.652a3.75 3.75 0 0 1 0-5.304m5.304 0a3.75 3.75 0 0 1 0 5.304m-7.425 2.121a6.75 6.75 0 0 1 0-9.546m9.546 0a6.75 6.75 0 0 1 0 9.546M5.106 18.894c-3.808-3.807-3.808-9.98 0-13.788m13.788 0c3.808 3.807 3.808 9.98 0 13.788M12 12h.008v.008H12V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-4">
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold <?php echo e($badgeColors[$serviceStatus['color']] ?? $badgeColors['gray']); ?>">
                    <span class="w-2 h-2 rounded-full <?php echo e($serviceStatus['color'] === 'success' ? 'bg-emerald-500 animate-pulse' : ($serviceStatus['color'] === 'warning' ? 'bg-amber-500' : 'bg-red-500')); ?>"></span>
                    <?php echo e($serviceStatus['label']); ?>

                </span>
                <div class="text-xs text-gray-500 dark:text-gray-400 mt-2 font-medium">disponibilité des envois push</div>
            </div>
        </div>
    </div>
</div>
<?php /**PATH /home/othnelio/fcm/restaurant-loyalty-api/resources/views/filament/resources/restaurant/components/quota-dashboard.blade.php ENDPATH**/ ?>