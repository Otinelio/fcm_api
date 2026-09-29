@php
    /** @var \App\Models\NotificationCampaign $campaign */
    $campaign = $record ?? ($getRecord ? $getRecord() : null);

    if (! $campaign) {
        return;
    }

    $recipientsCount = $campaign->recipients_count;
    $deliveredCount = $campaign->delivered_count;
    $failedCount = $campaign->failed_count;
    $readCount = $campaign->read_count;
    $readRate = $campaign->read_rate;

    $restaurant = $campaign->restaurant;
    $restaurantName = $restaurant?->name ?? 'Établissement inconnu';
    $restaurantLogo = $restaurant?->logo_url ?? \App\Support\AvatarHelper::forRestaurant($restaurant);

    $imageUrl = $campaign->image_url;
    if ($imageUrl) {
        $imageUrl = \App\Support\StorageUrlResolver::resolve($imageUrl);
    }

    $deliveryPercent = ($recipientsCount > 0 && $deliveredCount > 0)
        ? min(100, round(($deliveredCount / $recipientsCount) * 100, 1))
        : ($campaign->status === 'sent' ? 100 : 0);
@endphp

<div class="space-y-6">
    <!-- 1. GRILLE DES KPIs STATISTIQUES MÉTIER -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <!-- KPI 1 : Destinataires -->
        <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">Destinataires</span>
                <span class="p-2 rounded-lg bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-black tracking-tight text-slate-900 dark:text-white">{{ number_format($recipientsCount) }}</div>
                <div class="text-xs font-medium text-slate-500 dark:text-slate-400 mt-0.5">
                    {{ $campaign->target['recipient_type'] === 'all' ? 'Tous les clients du commerce' : 'Clients ciblés' }}
                </div>
            </div>
        </div>

        <!-- KPI 2 : Envoyés avec succès FCM -->
        <div class="rounded-xl border border-emerald-200 dark:border-emerald-900/50 bg-emerald-50/30 dark:bg-emerald-950/20 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-emerald-700 dark:text-emerald-400">Envoyés (FCM)</span>
                <span class="p-2 rounded-lg bg-emerald-100 dark:bg-emerald-900/50 text-emerald-600 dark:text-emerald-300">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12 3.269 3.125A59.769 59.769 0 0 1 21.485 12 59.768 59.768 0 0 1 3.27 20.875L5.999 12Zm0 0h7.5" />
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-black tracking-tight text-emerald-700 dark:text-emerald-300">{{ number_format($deliveredCount) }}</div>
                <div class="text-xs font-semibold text-emerald-600 dark:text-emerald-400 mt-0.5 flex items-center gap-1">
                    <span>✓ Confirmés transmis</span>
                    @if ($recipientsCount > 0)
                        <span>({{ $deliveryPercent }}%)</span>
                    @endif
                </div>
            </div>
        </div>

        <!-- KPI 3 : Échecs de distribution -->
        <div class="rounded-xl border {{ $failedCount > 0 ? 'border-rose-200 dark:border-rose-900/50 bg-rose-50/30 dark:bg-rose-950/20' : 'border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900' }} p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider {{ $failedCount > 0 ? 'text-rose-700 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400' }}">Échecs</span>
                <span class="p-2 rounded-lg {{ $failedCount > 0 ? 'bg-rose-100 dark:bg-rose-900/50 text-rose-600 dark:text-rose-300' : 'bg-slate-100 dark:bg-slate-800 text-slate-500 dark:text-slate-400' }}">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-black tracking-tight {{ $failedCount > 0 ? 'text-rose-700 dark:text-rose-300' : 'text-slate-700 dark:text-slate-300' }}">
                    {{ number_format($failedCount) }}
                </div>
                <div class="text-xs font-medium {{ $failedCount > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400' }} mt-0.5">
                    {{ $failedCount > 0 ? 'Non acheminés (voir détails)' : 'Aucun échec' }}
                </div>
            </div>
        </div>

        <!-- KPI 4 : Vus / Ouverts dans l'application -->
        <div class="rounded-xl border border-sky-200 dark:border-sky-900/50 bg-sky-50/30 dark:bg-sky-950/20 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-sky-700 dark:text-sky-400">Vus / Ouverts</span>
                <span class="p-2 rounded-lg bg-sky-100 dark:bg-sky-900/50 text-sky-600 dark:text-sky-300">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-black tracking-tight text-sky-700 dark:text-sky-300">{{ number_format($readCount) }}</div>
                <div class="text-xs font-medium text-sky-600 dark:text-sky-400 mt-0.5">
                    Notifications lues dans l'app
                </div>
            </div>
        </div>

        <!-- KPI 5 : Taux de lecture effectif -->
        <div class="rounded-xl border border-violet-200 dark:border-violet-900/50 bg-violet-50/30 dark:bg-violet-950/20 p-4 shadow-xs">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold uppercase tracking-wider text-violet-700 dark:text-violet-400">Taux de lecture</span>
                <span class="p-2 rounded-lg bg-violet-100 dark:bg-violet-900/50 text-violet-600 dark:text-violet-300">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                    </svg>
                </span>
            </div>
            <div class="mt-3">
                <div class="text-2xl font-black tracking-tight text-violet-700 dark:text-violet-300">
                    {{ $readRate !== null ? "{$readRate}%" : '—' }}
                </div>
                <div class="w-full bg-violet-200 dark:bg-violet-900/60 rounded-full h-1.5 mt-2 overflow-hidden">
                    <div class="bg-violet-600 h-1.5 rounded-full" style="width: {{ $readRate ?? 0 }}%"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. APERÇU RÉALISTE DE LA NOTIFICATION PUSH SMARTPHONE & DÉTAILS MÉTIER -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        <!-- Mockup Smartphone Notification (7 colonnes) -->
        <div class="lg:col-span-7 rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-100/60 dark:bg-slate-900/60 p-6 shadow-sm">
            <div class="flex items-center justify-between pb-3 border-b border-slate-200/80 dark:border-slate-800">
                <div class="flex items-center gap-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-indigo-600 text-white shadow-xs">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />
                        </svg>
                    </span>
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wider text-slate-800 dark:text-white">Aperçu Réaliste Smartphone</div>
                        <div class="text-[11px] text-slate-500">Rendu exact sur l'écran verrouillé du client</div>
                    </div>
                </div>
                <span class="px-2 py-0.5 text-[10px] font-bold rounded-full bg-indigo-100 dark:bg-indigo-950 text-indigo-700 dark:text-indigo-400">
                    Push FCM Mobile
                </span>
            </div>

            <!-- Fake Smartphone Screen -->
            <div class="mt-4 max-w-md mx-auto rounded-3xl bg-slate-900 dark:bg-black p-3.5 shadow-2xl border-4 border-slate-800">
                <!-- Status bar -->
                <div class="flex items-center justify-between px-3 pt-1 pb-3 text-slate-400 text-[10px] font-medium">
                    <span>9:41</span>
                    <div class="w-14 h-3.5 bg-slate-800 rounded-full mx-auto"></div>
                    <div class="flex items-center gap-1">
                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M12 3c-4.97 0-9 4.03-9 9 0 2.12.74 4.07 1.97 5.61L4.35 19.4c-.39.39-.39 1.02 0 1.41.39.39 1.02.39 1.41 0l1.9-1.9C9.28 19.59 10.58 20 12 20c4.97 0 9-4.03 9-9s-4.03-9-9-9z"/></svg>
                        <div class="w-4 h-2 border border-slate-400 rounded-xs p-0.5"><div class="w-full h-full bg-slate-400"></div></div>
                    </div>
                </div>

                <!-- Push Notification Banner Card -->
                <div class="rounded-2xl bg-white/95 dark:bg-slate-800/95 backdrop-blur-md p-4 shadow-xl border border-white/20 transition-all">
                    <!-- Notification Header -->
                    <div class="flex items-center justify-between pb-2 border-b border-slate-100 dark:border-slate-700/60">
                        <div class="flex items-center gap-2">
                            <img src="{{ $restaurantLogo }}" alt="{{ $restaurantName }}" class="w-6 h-6 rounded-full object-cover border border-slate-200 dark:border-slate-700" />
                            <span class="text-xs font-bold text-slate-900 dark:text-white truncate max-w-[170px]">{{ $restaurantName }}</span>
                            <span class="text-[10px] text-slate-400">• maintenant</span>
                        </div>
                        <span class="w-2 h-2 rounded-full bg-indigo-600 animate-pulse"></span>
                    </div>

                    <!-- Notification Body -->
                    <div class="mt-2.5 space-y-1.5">
                        <h4 class="text-sm font-extrabold text-slate-900 dark:text-white leading-snug">
                            {{ $campaign->title ?: 'Message de fidélité' }}
                        </h4>
                        <p class="text-xs text-slate-600 dark:text-slate-300 leading-relaxed break-words">
                            {{ $campaign->message ?: 'Découvrez notre offre exclusive dans votre application.' }}
                        </p>
                    </div>

                    <!-- Push Image if present -->
                    @if ($imageUrl)
                        <div class="mt-3 rounded-xl overflow-hidden border border-slate-200 dark:border-slate-700 shadow-xs max-h-48">
                            <img src="{{ $imageUrl }}" alt="Visuel campagne" class="w-full h-36 object-cover" />
                        </div>
                    @endif

                    <!-- Interactive Action Footer -->
                    <div class="mt-3 pt-2.5 border-t border-slate-100 dark:border-slate-700/50 flex items-center justify-between text-[11px]">
                        <span class="font-semibold text-indigo-600 dark:text-indigo-400 flex items-center gap-1">
                            <span>Toucher pour ouvrir l'offre</span>
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" /></svg>
                        </span>
                        <span class="text-[10px] text-slate-400">Miva Fid App</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Informations Métier & Calendrier (5 colonnes) -->
        <div class="lg:col-span-5 space-y-4">
            <!-- Card Établissement -->
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 shadow-xs">
                <div class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 pb-2 border-b border-slate-100 dark:border-slate-800">
                    Établissement Émetteur
                </div>
                <div class="mt-3 flex items-center gap-3">
                    <img src="{{ $restaurantLogo }}" alt="{{ $restaurantName }}" class="w-12 h-12 rounded-xl object-cover border border-slate-200 dark:border-slate-700 shadow-xs" />
                    <div>
                        <div class="text-base font-extrabold text-slate-900 dark:text-white">{{ $restaurantName }}</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">
                            {{ $restaurant?->city ?? 'Commerce partenaire' }}
                            @if ($restaurant?->phone)
                                • {{ $restaurant->phone }}
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card Paramètres d'Envoi -->
            <div class="rounded-xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-5 shadow-xs space-y-3">
                <div class="text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400 pb-2 border-b border-slate-100 dark:border-slate-800">
                    Paramètres de Diffusion
                </div>

                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div>
                        <span class="text-slate-400 block">Type de campagne</span>
                        <span class="font-bold text-slate-800 dark:text-slate-200">{{ $campaign->readable_type }}</span>
                    </div>
                    <div>
                        <span class="text-slate-400 block">Statut</span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full font-bold text-[11px]
                            {{ match($campaign->status) {
                                'sent' => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
                                'scheduled' => 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300',
                                'draft' => 'bg-slate-100 text-slate-800 dark:bg-slate-800 dark:text-slate-300',
                                default => 'bg-rose-100 text-rose-800 dark:bg-rose-950 dark:text-rose-300',
                            } }}">
                            {{ $campaign->readable_status }}
                        </span>
                    </div>
                    <div>
                        <span class="text-slate-400 block">Mode d'envoi</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200">
                            {{ $campaign->kind === 'automated' ? 'Scénario automatisé' : 'Envoi direct / manuel' }}
                        </span>
                    </div>
                    <div>
                        <span class="text-slate-400 block">Canal technique</span>
                        <span class="font-semibold text-slate-800 dark:text-slate-200">Firebase Push (FCM v1)</span>
                    </div>
                </div>

                <div class="pt-2 border-t border-slate-100 dark:border-slate-800 text-xs space-y-1.5">
                    @if ($campaign->scheduled_at)
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Date programmée :</span>
                            <span class="font-bold text-slate-800 dark:text-slate-200">{{ $campaign->scheduled_at->format('d/m/Y à H:i') }}</span>
                        </div>
                    @endif
                    @if ($campaign->sent_at)
                        <div class="flex items-center justify-between">
                            <span class="text-slate-500">Date d'envoi effectif :</span>
                            <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ $campaign->sent_at->format('d/m/Y à H:i:s') }}</span>
                        </div>
                    @endif
                    <div class="flex items-center justify-between">
                        <span class="text-slate-500">Création initiale :</span>
                        <span class="text-slate-700 dark:text-slate-300">{{ $campaign->created_at?->format('d/m/Y H:i') }}</span>
                    </div>
                </div>
            </div>

            <!-- Card Échecs s'il y en a -->
            @if ($failedCount > 0)
                <div class="rounded-xl border border-rose-200 dark:border-rose-900/60 bg-rose-50/50 dark:bg-rose-950/20 p-4 text-xs">
                    <div class="flex items-center gap-2 font-bold text-rose-700 dark:text-rose-400 mb-1">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                        </svg>
                        <span>Incident d'acheminement sur {{ $failedCount }} client(s)</span>
                    </div>
                    <p class="text-rose-600 dark:text-rose-300">
                        La majorité des échecs survient lorsque les clients ciblés n'ont pas encore installé l'application mobile sur leur smartphone ou ont refusé les notifications lors de la première installation.
                    </p>
                </div>
            @endif
        </div>
    </div>
</div>
