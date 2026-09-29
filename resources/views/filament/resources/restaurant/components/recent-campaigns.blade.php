@php
    /** @var \App\Models\Restaurant $record */
    $record = $getRecord();
    $campaigns = $record->notificationCampaigns()
        ->where('status', '!=', 'draft')
        ->latest()
        ->take(10)
        ->get();

    $statusColors = [
        'sent'      => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400',
        'scheduled' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        'failed'    => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
        'draft'     => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400',
    ];
@endphp

<div class="rounded-2xl bg-white dark:bg-gray-900 shadow-sm border border-gray-200 dark:border-gray-800 overflow-hidden">
    @if ($campaigns->isEmpty())
        <div class="p-8 text-center text-gray-400 dark:text-gray-500">
            <svg class="w-10 h-10 mx-auto mb-2 text-gray-300 dark:text-gray-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
            </svg>
            <p class="text-sm font-medium">Aucune campagne envoyée pour cet établissement.</p>
        </div>
    @else
        <table class="w-full text-sm">
            <thead class="bg-gray-50/75 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Campagne</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Type</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Crédits débités</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Statut</th>
                    <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Date d'envoi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach ($campaigns as $campaign)
                    @php
                        $targetRecipients = (int) ($campaign->target['recipients_count'] ?? 0);
                        $logsCount = $campaign->logs()->count();
                        $credits = max($targetRecipients, $logsCount);
                    @endphp
                    <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-800/40 transition-colors">
                        <td class="px-4 py-3">
                            <p class="font-bold text-gray-900 dark:text-white truncate max-w-[240px]">
                                {{ $campaign->title ?: '(Sans titre)' }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate max-w-[240px] mt-0.5">
                                {{ \Illuminate\Support\Str::limit($campaign->message, 50) }}
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ $campaign->readable_type }}</span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex items-center gap-1 text-sm font-black text-amber-600 dark:text-amber-400">
                                -{{ $credits }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $statusColors[$campaign->status] ?? $statusColors['draft'] }}">
                                {{ $campaign->readable_status }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right text-xs text-gray-500 dark:text-gray-400 font-medium">
                            {{ ($campaign->sent_at ?? $campaign->created_at)->format('d/m/Y H:i') }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
