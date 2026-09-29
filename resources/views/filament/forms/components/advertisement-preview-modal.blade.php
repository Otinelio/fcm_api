@php
    $imageUrl = $record->image_url;
    $title = $record->title ?: 'Titre de l\'offre';
    $subtitle = $record->subtitle ?: 'OFFRE SPÉCIALE';
    $description = $record->description ?: 'Aucune description fournie.';
    $linkUrl = $record->link_url;
    $isActive = $record->is_active;
@endphp

<div class="space-y-4 py-2">
    <!-- Phone Card Wrapper Mockup -->
    <div class="relative w-full max-w-md mx-auto overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-700 via-purple-700 to-indigo-900 p-5 shadow-2xl text-white">
        <!-- Background Overlay Image -->
        @if ($imageUrl)
            <div class="absolute inset-0 z-0">
                <img src="{{ $imageUrl }}" alt="{{ $title }}" class="w-full h-full object-cover opacity-35 filter brightness-95" />
                <div class="absolute inset-0 bg-gradient-to-r from-indigo-950/90 via-indigo-900/60 to-transparent"></div>
            </div>
        @else
            <div class="absolute -right-8 -bottom-8 w-44 h-44 rounded-full bg-white/5 blur-xl pointer-events-none"></div>
            <div class="absolute right-6 top-6 text-5xl opacity-40 select-none">📢</div>
        @endif

        <div class="absolute right-4 top-3 text-lg opacity-80 select-none">🪙</div>
        <div class="absolute right-12 bottom-3 text-base opacity-75 select-none">✨</div>

        <div class="relative z-10 flex flex-col justify-between min-h-[145px] max-w-[70%]">
            <div>
                <span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-violet-500/80 text-white backdrop-blur-xs ring-1 ring-white/20 shadow-xs mb-2">
                    {{ $subtitle }}
                </span>

                <h3 class="text-base font-black leading-tight text-white drop-shadow-sm mb-1 line-clamp-2">
                    {{ $title }}
                </h3>

                <p class="text-xs text-indigo-100/90 leading-snug line-clamp-2 drop-shadow-xs">
                    {{ $description }}
                </p>
            </div>

            <div class="pt-3">
                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white text-indigo-700 text-xs font-bold shadow-md">
                    <span>En profiter</span>
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Metadata Details -->
    <div class="bg-slate-50 dark:bg-slate-900/80 rounded-xl p-3 border border-slate-200/80 dark:border-slate-800 text-xs space-y-2">
        <div class="flex items-center justify-between">
            <span class="text-slate-500">Statut de diffusion :</span>
            @if ($isActive)
                <span class="inline-flex items-center gap-1 font-semibold text-emerald-600 dark:text-emerald-400">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Active
                </span>
            @else
                <span class="inline-flex items-center gap-1 font-semibold text-rose-600 dark:text-rose-400">
                    <span class="w-2 h-2 rounded-full bg-rose-500"></span> Désactivée
                </span>
            @endif
        </div>

        @if ($linkUrl)
            <div class="flex items-center justify-between gap-4">
                <span class="text-slate-500 shrink-0">Lien externe :</span>
                <a href="{{ $linkUrl }}" target="_blank" class="text-indigo-600 dark:text-indigo-400 font-medium underline truncate hover:text-indigo-500">
                    {{ $linkUrl }}
                </a>
            </div>
        @endif

        <div class="flex items-center justify-between">
            <span class="text-slate-500">Date de début :</span>
            <span class="font-medium text-slate-700 dark:text-slate-300">
                {{ $record->starts_at ? $record->starts_at->format('d/m/Y H:i') : 'Immédiate' }}
            </span>
        </div>

        <div class="flex items-center justify-between">
            <span class="text-slate-500">Date de fin :</span>
            <span class="font-medium text-slate-700 dark:text-slate-300">
                {{ $record->ends_at ? $record->ends_at->format('d/m/Y H:i') : 'Indéterminée (illimitée)' }}
            </span>
        </div>
    </div>
</div>
