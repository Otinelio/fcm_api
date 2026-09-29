@php
    /** @var \App\Models\Restaurant $record */
    $record = $getRecord();
    $history = $record->monthly_consumption;
    $max = max(1, max(array_column($history, 'count')));
@endphp

<div class="p-4 rounded-xl bg-white dark:bg-gray-900 shadow-sm border border-gray-200 dark:border-gray-700">
    <div class="flex items-end gap-3 h-48">
        @foreach ($history as $entry)
            @php
                $heightPercent = $max > 0 ? ($entry['count'] / $max) * 100 : 0;
                $barColor = match (true) {
                    $entry['count'] === 0 => 'bg-gray-200 dark:bg-gray-700',
                    $entry['count'] > $max * 0.7 => 'bg-red-400 dark:bg-red-500',
                    $entry['count'] > $max * 0.4 => 'bg-amber-400 dark:bg-amber-500',
                    default => 'bg-emerald-400 dark:bg-emerald-500',
                };
            @endphp
            <div class="flex-1 flex flex-col items-center gap-2">
                <span class="text-xs font-medium text-gray-700 dark:text-gray-300">
                    {{ $entry['count'] }}
                </span>
                <div class="w-full rounded-t-lg transition-all duration-700 ease-out {{ $barColor }}"
                     style="height: {{ max(4, $heightPercent) }}%; min-height: 4px;">
                </div>
                <span class="text-[10px] text-gray-500 dark:text-gray-400 whitespace-nowrap">
                    {{ $entry['month'] }}
                </span>
            </div>
        @endforeach
    </div>

    @if (collect($history)->sum('count') === 0)
        <p class="text-center text-sm text-gray-400 dark:text-gray-500 mt-4">
            Aucune consommation enregistrée sur les 6 derniers mois.
        </p>
    @endif
</div>
