@php
    /** @var \App\Models\Client $record */
    $record = $getRecord();

    $fullName = $record->full_name ?: 'Client';
    $avatar = $record->avatar_url ?? \App\Support\AvatarHelper::forClient($record);
    $isVerified = (bool) $record->phone_verified_at;
    $isOAuth = $record->isOAuthUser();
    $isComplete = $record->isProfileComplete();

    // Cartes de fidélité avec programmes et établissements
    $cards = \App\Models\LoyaltyCard::with(['restaurant', 'loyaltyProgram'])
        ->where('client_id', $record->id)
        ->orderByDesc('last_activity_at')
        ->get();

    // Récompenses du client (via ses cartes)
    $cardIds = $cards->pluck('id')->toArray();
    $rewards = \App\Models\LoyaltyReward::whereIn('loyalty_card_id', $cardIds)
        ->with('loyaltyCard.restaurant')
        ->orderByDesc('unlocked_at')
        ->limit(10)
        ->get();

    // Dernières transactions
    $transactions = \App\Models\LoyaltyTransaction::whereIn('loyalty_card_id', $cardIds)
        ->with(['staffUser', 'loyaltyCard.restaurant'])
        ->orderByDesc('created_at')
        ->limit(15)
        ->get();

    // Parrainages
    $referralsMade = $record->referralsMade()->with('referredClient')->latest()->limit(5)->get();
    $referralReceived = $record->referralReceived;

    // Établissements distincts
    $establishments = $cards->map(fn ($c) => $c->restaurant)->filter()->unique('id');

    // Statistiques
    $totalStamps = $cards->sum(fn ($c) => (int) ($c->progress['stamps_current'] ?? 0));
    $totalCashback = $cards->sum(fn ($c) => (float) $c->cashback_available_fcfa);
    $totalRewards = $rewards->count();
    $totalCycles = $cards->sum('cycles_completed');
@endphp

<div class="space-y-6">

    {{-- ═══════════════════════════════════════════════════════════
         1. EN-TÊTE PROFIL CLIENT
         ═══════════════════════════════════════════════════════════ --}}
    <div class="rounded-2xl overflow-hidden shadow-lg border border-slate-200 dark:border-slate-800">
        <div class="relative bg-gradient-to-br from-sky-600 via-blue-600 to-indigo-700 px-6 py-8 text-white">
            <div class="absolute top-0 right-0 w-64 h-64 opacity-10">
                <svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg"><path fill="white" d="M39.9,-51.8C51.4,-44.3,59.8,-31.6,63.7,-17.6C67.6,-3.6,67,11.8,61.2,24.8C55.5,37.8,44.6,48.5,31.8,55.3C19,62.1,4.3,65.1,-10.3,63.5C-24.9,61.9,-39.4,55.7,-49.9,45.2C-60.4,34.8,-66.9,20,-68.3,4.5C-69.6,-11,-65.9,-27.2,-56,-38.2C-46.1,-49.2,-30,-55,-15.2,-56.6C-0.3,-58.2,13.3,-55.5,25.4,-55.3C37.6,-55.1,48.3,-57.4,39.9,-51.8Z" transform="translate(100 100)" /></svg>
            </div>

            <div class="flex items-start gap-5 relative z-10">
                <img src="{{ $avatar }}" alt="{{ $fullName }}" class="w-20 h-20 rounded-2xl object-cover border-3 border-white/30 shadow-xl" />
                <div class="flex-1">
                    <div class="flex items-center gap-3">
                        <h2 class="text-2xl font-black tracking-tight">{{ $fullName }}</h2>
                        @if ($isVerified)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-400/25 text-emerald-100">
                                ✓ Vérifié
                            </span>
                        @endif
                    </div>

                    <div class="mt-2 flex flex-wrap items-center gap-3 text-sm text-white/70">
                        @if ($record->phone)
                            <span class="flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 4.5v2.25Z" /></svg>
                                {{ $record->phone }}
                            </span>
                        @endif
                        @if ($record->email)
                            <span class="flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75" /></svg>
                                {{ $record->email }}
                            </span>
                        @endif
                        @if ($record->city)
                            <span class="flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1 1 15 0Z" /></svg>
                                {{ $record->city }}{{ $record->country ? ", {$record->country}" : '' }}
                            </span>
                        @endif
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        @if ($isOAuth)
                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-white/15 backdrop-blur-sm">
                                {{ ucfirst($record->oauth_provider) }}
                            </span>
                        @else
                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-white/15 backdrop-blur-sm">
                                Classique
                            </span>
                        @endif
                        <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-white/15 backdrop-blur-sm">
                            Inscrit le {{ $record->created_at?->format('d/m/Y') }}
                        </span>
                        @if (!$isComplete)
                            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-400/25 text-amber-100">
                                Profil incomplet
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════
         2. STATISTIQUES GLOBALES
         ═══════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
        <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
            <div class="text-xs font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400">Établissements</div>
            <div class="mt-2 text-2xl font-extrabold text-slate-900 dark:text-white">{{ $establishments->count() }}</div>
            <div class="text-[11px] text-slate-500 dark:text-slate-400">commerce(s) fréquenté(s)</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
            <div class="text-xs font-bold uppercase tracking-wider text-violet-600 dark:text-violet-400">Cartes</div>
            <div class="mt-2 text-2xl font-extrabold text-slate-900 dark:text-white">{{ $cards->count() }}</div>
            <div class="text-[11px] text-slate-500 dark:text-slate-400">carte(s) de fidélité</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
            <div class="text-xs font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400">Tampons</div>
            <div class="mt-2 text-2xl font-extrabold text-slate-900 dark:text-white">{{ number_format($totalStamps) }}</div>
            <div class="text-[11px] text-slate-500 dark:text-slate-400">collectés au total</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
            <div class="text-xs font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400">Récompenses</div>
            <div class="mt-2 text-2xl font-extrabold text-slate-900 dark:text-white">{{ $totalRewards }}</div>
            <div class="text-[11px] text-slate-500 dark:text-slate-400">débloquées</div>
        </div>
        <div class="rounded-xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 p-4 shadow-xs">
            <div class="text-xs font-bold uppercase tracking-wider text-sky-600 dark:text-sky-400">Cashback</div>
            <div class="mt-2 text-2xl font-extrabold text-slate-900 dark:text-white">{{ number_format($totalCashback, 0, ',', ' ') }}</div>
            <div class="text-[11px] text-slate-500 dark:text-slate-400">FCFA disponibles</div>
        </div>
    </div>

    {{-- ═══════════════════════════════════════════════════════════
         3. CARTES DE FIDÉLITÉ
         ═══════════════════════════════════════════════════════════ --}}
    @if ($cards->count() > 0)
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-xs">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-4">💳 Cartes de fidélité</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                @foreach ($cards as $card)
                    @php
                        $cRestaurant = $card->restaurant;
                        $cProgram = $card->loyaltyProgram;
                        $cPercent = $card->percent;
                        $cLevel = $card->level;
                        $cGoal = $card->goal;
                        $cCurrent = (int) ($card->progress['stamps_current'] ?? 0);
                        $cType = $cProgram?->type ?? 'stamp';
                        $cLogo = $cRestaurant?->logo_url ?? \App\Support\AvatarHelper::forRestaurant($cRestaurant);

                        $cStatusColor = match ($card->status) {
                            'active' => 'border-emerald-300 dark:border-emerald-800',
                            'completed' => 'border-blue-300 dark:border-blue-800',
                            'expired' => 'border-red-300 dark:border-red-800',
                            default => 'border-slate-300 dark:border-slate-700',
                        };
                        $cStatusLabel = match ($card->status) {
                            'active' => '🟢 Active',
                            'completed' => '🔵 Complétée',
                            'expired' => '🔴 Expirée',
                            default => $card->status,
                        };
                        $cRingColor = match (true) {
                            $cPercent >= 90 => '#10b981',
                            $cPercent >= 60 => '#3b82f6',
                            $cPercent >= 30 => '#f59e0b',
                            default => '#6366f1',
                        };
                    @endphp
                    <a href="{{ \App\Filament\Resources\LoyaltyCardResource::getUrl('view', ['record' => $card->id]) }}"
                       class="block rounded-xl border-2 {{ $cStatusColor }} bg-slate-50/50 dark:bg-slate-800/30 p-4 hover:shadow-md hover:scale-[1.01] transition-all">
                        <div class="flex items-center gap-3 mb-3">
                            <img src="{{ $cLogo }}" alt="" class="w-10 h-10 rounded-lg object-cover border border-slate-200 dark:border-slate-700" />
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-bold text-slate-900 dark:text-white truncate">{{ $cRestaurant?->name ?? 'Établissement' }}</div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400">{{ $cProgram?->name ?? 'Programme' }}</div>
                            </div>
                            <span class="text-[11px] font-bold">{{ $cStatusLabel }}</span>
                        </div>

                        {{-- Barre de progression --}}
                        <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-2 overflow-hidden">
                            <div class="h-2 rounded-full transition-all" style="width: {{ min(100, $cPercent) }}%; background: {{ $cRingColor }};"></div>
                        </div>
                        <div class="flex items-center justify-between mt-1.5 text-[11px]">
                            <span class="text-slate-500 dark:text-slate-400">
                                @if ($cType === 'cashback')
                                    {{ number_format((float) $card->cashback_available_fcfa, 0, ',', ' ') }} FCFA
                                @elseif ($cGoal)
                                    {{ $cCurrent }} / {{ $cGoal }}
                                @else
                                    {{ $cPercent }}%
                                @endif
                            </span>
                            @if ($cLevel && $cLevel['name'])
                                <span class="font-bold text-amber-600 dark:text-amber-400">{{ $cLevel['name'] }}</span>
                            @endif
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @else
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-8 text-center shadow-xs">
            <div class="text-4xl mb-2">💳</div>
            <div class="text-sm font-bold text-slate-700 dark:text-slate-300">Aucune carte de fidélité</div>
            <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">Ce client n'a encore rejoint aucun programme.</div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════
         4. RÉCOMPENSES
         ═══════════════════════════════════════════════════════════ --}}
    @if ($rewards->count() > 0)
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-xs">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-4">🎁 Récompenses récentes</h3>
            <div class="space-y-3">
                @foreach ($rewards as $reward)
                    @php
                        $rStatus = $reward->is_expired ? 'expired' : $reward->status;
                        $rBg = match ($rStatus) {
                            'available' => 'border-emerald-200 dark:border-emerald-800 bg-emerald-50/50 dark:bg-emerald-950/20',
                            'used' => 'border-blue-200 dark:border-blue-800 bg-blue-50/50 dark:bg-blue-950/20',
                            'canceled' => 'border-slate-200 dark:border-slate-700 opacity-60',
                            'expired' => 'border-red-200 dark:border-red-800 opacity-70',
                            default => 'border-slate-200',
                        };
                        $rBadge = match ($rStatus) {
                            'available' => ['🟢 Disponible', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-300'],
                            'used' => ['✅ Utilisée', 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-300'],
                            'canceled' => ['❌ Annulée', 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-400'],
                            'expired' => ['⏰ Expirée', 'bg-red-100 text-red-700 dark:bg-red-900/50 dark:text-red-300'],
                            default => [$rStatus, 'bg-slate-100 text-slate-600'],
                        };
                    @endphp
                    <div class="flex items-center justify-between p-3 rounded-xl border {{ $rBg }}">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="text-xl flex-shrink-0">{{ $reward->is_surprise ? '🎊' : '🎁' }}</span>
                            <div class="min-w-0">
                                <div class="text-sm font-bold text-slate-900 dark:text-white truncate">{{ $reward->title ?: 'Récompense' }}</div>
                                <div class="text-[11px] text-slate-500 dark:text-slate-400 truncate">
                                    {{ $reward->loyaltyCard?->restaurant?->name ?? 'Établissement' }} · {{ $reward->unlocked_at?->format('d/m/Y') }}
                                </div>
                            </div>
                        </div>
                        <span class="text-[11px] font-bold px-2.5 py-1 rounded-full flex-shrink-0 {{ $rBadge[1] }}">{{ $rBadge[0] }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════
         5. ACTIVITÉ RÉCENTE
         ═══════════════════════════════════════════════════════════ --}}
    @if ($transactions->count() > 0)
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-xs">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-4">📋 Activité récente</h3>
            <div class="divide-y divide-slate-100 dark:divide-slate-800">
                @foreach ($transactions as $tx)
                    @php
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
                    @endphp
                    <div class="flex items-center justify-between py-3 {{ $txCanceled ? 'opacity-50' : '' }}">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="text-lg flex-shrink-0">{{ $txIcon }}</span>
                            <div class="min-w-0">
                                <div class="text-sm font-semibold text-slate-800 dark:text-slate-200 {{ $txCanceled ? 'line-through' : '' }}">
                                    {{ $txLabel }}
                                    @if ($tx->value && $tx->value > 0)
                                        <span class="text-xs font-bold text-indigo-600 dark:text-indigo-400">+{{ intval($tx->value) }}</span>
                                    @endif
                                </div>
                                <div class="text-[11px] text-slate-400 dark:text-slate-500 truncate">
                                    {{ $tx->loyaltyCard?->restaurant?->name ?? '' }} · {{ $tx->created_at?->format('d/m/Y H:i') }}
                                    @if ($tx->staffUser)
                                        · {{ $tx->staffUser->name ?? 'Personnel' }}
                                    @endif
                                </div>
                            </div>
                        </div>
                        @if ($txCanceled)
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400 flex-shrink-0">Annulé</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════
         6. PARRAINAGES
         ═══════════════════════════════════════════════════════════ --}}
    @if ($referralsMade->count() > 0 || $referralReceived)
        <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 p-6 shadow-xs">
            <h3 class="text-sm font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-4">🤝 Parrainages</h3>

            @if ($referralReceived)
                <div class="mb-4 p-3 rounded-xl border border-sky-200 dark:border-sky-800 bg-sky-50/50 dark:bg-sky-950/20">
                    <div class="text-xs font-bold text-sky-700 dark:text-sky-300">Parrainé par</div>
                    <div class="text-sm font-semibold text-slate-800 dark:text-slate-200 mt-0.5">
                        {{ $referralReceived->referrerClient?->full_name ?? 'Client' }}
                    </div>
                </div>
            @endif

            @if ($referralsMade->count() > 0)
                <div class="text-xs font-bold text-slate-500 dark:text-slate-400 mb-2">Filleul(s) parrainé(s)</div>
                <div class="space-y-2">
                    @foreach ($referralsMade as $ref)
                        <div class="flex items-center justify-between p-2.5 rounded-lg bg-slate-50 dark:bg-slate-800/50">
                            <span class="text-sm font-medium text-slate-800 dark:text-slate-200">
                                {{ $ref->referredClient?->full_name ?? 'Client' }}
                            </span>
                            <span class="text-[11px] text-slate-500 dark:text-slate-400">{{ $ref->created_at?->format('d/m/Y') }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- ═══════════════════════════════════════════════════════════
         7. INFORMATIONS TECHNIQUES (SECONDAIRE)
         ═══════════════════════════════════════════════════════════ --}}
    <details class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-slate-50 dark:bg-slate-900/50 shadow-xs">
        <summary class="px-6 py-4 cursor-pointer text-xs font-bold uppercase tracking-wider text-slate-400 dark:text-slate-500 hover:text-slate-600 dark:hover:text-slate-300 transition-colors select-none">
            🔧 Informations techniques
        </summary>
        <div class="px-6 pb-5 grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
            <div>
                <span class="text-slate-400 block">ID Client</span>
                <span class="font-mono font-bold text-slate-700 dark:text-slate-300">{{ $record->id }}</span>
            </div>
            <div>
                <span class="text-slate-400 block">UUID</span>
                <span class="font-mono text-[11px] text-slate-500 dark:text-slate-400 break-all">{{ $record->uuid }}</span>
            </div>
            <div>
                <span class="text-slate-400 block">Méthode de connexion</span>
                <span class="font-bold text-slate-700 dark:text-slate-300">{{ $isOAuth ? ucfirst($record->oauth_provider) : 'Mot de passe' }}</span>
            </div>
            <div>
                <span class="text-slate-400 block">Téléphone vérifié le</span>
                <span class="text-slate-600 dark:text-slate-300">{{ $record->phone_verified_at?->format('d/m/Y H:i') ?? '—' }}</span>
            </div>
            <div>
                <span class="text-slate-400 block">FCM Token</span>
                <span class="font-mono text-[10px] text-slate-500 dark:text-slate-400 break-all">{{ $record->fcm_token ? \Illuminate\Support\Str::limit($record->fcm_token, 40) : '—' }}</span>
            </div>
            <div>
                <span class="text-slate-400 block">Créé le</span>
                <span class="text-slate-600 dark:text-slate-300">{{ $record->created_at?->format('d/m/Y H:i:s') }}</span>
            </div>
            <div>
                <span class="text-slate-400 block">Modifié le</span>
                <span class="text-slate-600 dark:text-slate-300">{{ $record->updated_at?->format('d/m/Y H:i:s') }}</span>
            </div>
            <div>
                <span class="text-slate-400 block">Date de naissance</span>
                <span class="text-slate-600 dark:text-slate-300">{{ $record->birthdate?->format('d/m/Y') ?? '—' }}</span>
            </div>
        </div>
    </details>

</div>
