@php
    $containerState = (isset($field) && method_exists($field, 'getContainer')) ? ($field->getContainer()?->getRawState() ?? []) : [];

    $title = !empty($title) ? $title : ($containerState['title'] ?? 'Titre de l\'offre publicitaire');
    $subtitle = !empty($subtitle) ? $subtitle : ($containerState['subtitle'] ?? 'OFFRE SPÉCIALE');
    $description = !empty($description) ? $description : ($containerState['description'] ?? 'Découvrez notre offre promotionnelle exclusive sur votre application Miva Fid.');
    $linkUrl = $link_url ?? ($linkUrl ?? ($containerState['link_url'] ?? null));
    $isActive = isset($is_active) ? (bool) $is_active : (isset($isActive) ? (bool) $isActive : ($containerState['is_active'] ?? true));
    $startsAt = $starts_at ?? ($startsAt ?? ($containerState['starts_at'] ?? null));
    $endsAt = $ends_at ?? ($endsAt ?? ($containerState['ends_at'] ?? null));
    $rawImage = $image_path ?? ($rawImage ?? ($containerState['image_path'] ?? null));

    $imageUrl = null;
    if ($rawImage) {
        if (is_string($rawImage)) {
            $imageUrl = \App\Support\StorageUrlResolver::resolve($rawImage);
        } elseif (is_array($rawImage)) {
            $first = reset($rawImage);
            if (is_string($first)) {
                $imageUrl = \App\Support\StorageUrlResolver::resolve($first);
            } elseif (is_object($first) && method_exists($first, 'temporaryUrl')) {
                try {
                    $imageUrl = $first->temporaryUrl();
                } catch (\Throwable $e) {
                    $imageUrl = null;
                }
            }
        } elseif (is_object($rawImage) && method_exists($rawImage, 'temporaryUrl')) {
            try {
                $imageUrl = $rawImage->temporaryUrl();
            } catch (\Throwable $e) {
                $imageUrl = null;
            }
        }
    }
@endphp

<div class="space-y-3">
    <div class="flex items-center justify-between pb-1 border-b border-slate-200 dark:border-slate-800">
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center justify-center w-6 h-6 rounded-md bg-indigo-50 dark:bg-indigo-950/60 text-indigo-600 dark:text-indigo-400">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 1.5H8.25A2.25 2.25 0 0 0 6 3.75v16.5a2.25 2.25 0 0 0 2.25 2.25h7.5A2.25 2.25 0 0 0 18 20.25V3.75a2.25 2.25 0 0 0-2.25-2.25H13.5m-3 0V3h3V1.5m-3 0h3m-3 18.75h3" />
                </svg>
            </span>
            <span class="text-xs font-bold uppercase tracking-wider text-slate-700 dark:text-slate-300">
                Aperçu Réaliste (Application Mobile)
            </span>
        </div>
        <span class="text-[11px] font-medium text-slate-400">
            Rendu live au format carrousel
        </span>
    </div>

    <!-- Phone Card Wrapper Mockup -->
    <div class="relative w-full max-w-lg mx-auto overflow-hidden rounded-2xl bg-gradient-to-br from-indigo-700 via-purple-700 to-indigo-900 p-5 shadow-xl text-white transition-all duration-300">
        <!-- Background Overlay Image -->
        @if ($imageUrl)
            <div class="absolute inset-0 z-0">
                <img src="{{ $imageUrl }}" alt="Visuel publicité" class="w-full h-full object-cover opacity-35 filter brightness-95" />
                <div class="absolute inset-0 bg-gradient-to-r from-indigo-950/90 via-indigo-900/60 to-transparent"></div>
            </div>
        @else
            <!-- Placeholder pattern when no image is uploaded -->
            <div class="absolute -right-8 -bottom-8 w-44 h-44 rounded-full bg-white/5 blur-xl pointer-events-none"></div>
            <div class="absolute right-6 top-6 text-5xl opacity-40 select-none">📢</div>
        @endif

        <!-- Floating Elements -->
        <div class="absolute right-4 top-3 text-lg opacity-80 select-none">🪙</div>
        <div class="absolute right-12 bottom-3 text-base opacity-75 select-none">✨</div>

        <!-- Banner Content -->
        <div class="relative z-10 flex flex-col justify-between min-h-[140px] max-w-[70%]">
            <div>
                <!-- Badge Subtitle -->
                <span class="inline-block px-2.5 py-0.5 rounded-full text-[10px] font-black uppercase tracking-wider bg-violet-500/80 text-white backdrop-blur-xs ring-1 ring-white/20 shadow-xs mb-2">
                    {{ $subtitle }}
                </span>

                <!-- Title -->
                <h3 class="text-base font-black leading-tight text-white drop-shadow-sm mb-1 line-clamp-2">
                    {{ $title }}
                </h3>

                <!-- Description -->
                <p class="text-xs text-indigo-100/90 leading-snug line-clamp-2 drop-shadow-xs">
                    {{ $description }}
                </p>
            </div>

            <!-- Call to action button -->
            <div class="pt-3">
                <div class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white text-indigo-700 text-xs font-bold shadow-md hover:bg-indigo-50 transition-colors">
                    <span>En profiter</span>
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Metadata & Quality Indicators -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 pt-1 text-[11px]">
        <div class="flex items-center gap-1.5 p-2 rounded-lg bg-slate-50 dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800">
            <span class="w-2 h-2 rounded-full {{ $isActive ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
            <span class="text-slate-600 dark:text-slate-400">Statut :</span>
            <span class="font-bold {{ $isActive ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">
                {{ $isActive ? 'Active' : 'Inactive' }}
            </span>
        </div>

        <div class="flex items-center gap-1.5 p-2 rounded-lg bg-slate-50 dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800">
            <span class="text-slate-600 dark:text-slate-400">Format :</span>
            <span class="font-bold text-indigo-600 dark:text-indigo-400">WebP 16:9</span>
        </div>

        <div class="flex items-center gap-1.5 p-2 rounded-lg bg-slate-50 dark:bg-slate-900 border border-slate-200/80 dark:border-slate-800 truncate col-span-2">
            <span class="text-slate-600 dark:text-slate-400">Lien :</span>
            <span class="font-medium text-slate-700 dark:text-slate-300 truncate">
                {{ $linkUrl ?: 'Aucune redirection' }}
            </span>
        </div>
    </div>
</div>
