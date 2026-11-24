<x-manga-layout title="BiblioManga — Read Manga & Comics Online for Free">
    @php
        $isMangaHome = request()->routeIs('manga.index', 'landing') && !request()->hasAny(['category', 'genre', 'q', 'search', 'page']);
    @endphp

    @if($isMangaHome)
        @include('manga.partials.hero')
    @endif

    <div class="{{ $isMangaHome ? 'mx-auto w-full max-w-[1440px] px-4 sm:px-6 lg:px-7 space-y-9 pt-3 sm:pt-4 relative z-20' : 'space-y-9' }}">

        @if($isMangaHome)
            {{-- ===== CONTINUE READING (manga history only) ===== --}}
            <section x-data="{
                        items: JSON.parse(localStorage.getItem('bibliomanga_history') || '[]'),
                        rel(item) {
                            const raw = item.updatedAt;
                            if (raw) {
                                const d = Number(raw);
                                if (d && d > 1577836800000) {
                                    const diff = Math.max(0, Date.now() - d);
                                    const m = Math.floor(diff / 60000);
                                    if (m < 1) return 'Read just now';
                                    if (m < 60) return 'Read ' + m + 'm ago';
                                    const h = Math.floor(m / 60);
                                    if (h < 24) return 'Read ' + h + 'h ago';
                                    return 'Read ' + Math.floor(h / 24) + 'd ago';
                                }
                            }
                            if (item.readAt && typeof item.readAt === 'string') {
                                return 'Read ' + item.readAt;
                            }
                            return 'Recently read';
                        }
                     }"
                     x-show="items.length > 0" x-cloak>
                <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-[#84948a]">Pick up where you left off</p>
                        <h2 class="font-display text-xl font-bold text-[#172a37] sm:text-2xl">Continue Reading</h2>
                    </div>
                    <x-section-link @click="openHistory()" class="!bg-white !border-[#e0d9c8] !text-[#0f766e] dark:!bg-[#172a37] dark:!border-[#223440] dark:!text-[#2dd4bf] relative z-30 shadow-sm">View all history</x-section-link>
                </div>

                <div class="rail-x no-scrollbar">
                    <template x-for="item in items.slice(0, 10)" :key="item.id + '-' + (item.chapterId || '')">
                        <a :href="continueHref(item)"
                           class="group flex w-[280px] items-center gap-3 rounded-2xl border border-[#e0d9c8] bg-white/80 p-2.5 shadow-sm transition hover:border-[#0f766e]/50 hover:shadow-md sm:w-[320px]">
                            <img :src="item.coverUrl || 'https://via.placeholder.com/60x84?text=No+Cover'" alt="" loading="lazy" decoding="async"
                                 class="h-[84px] w-[58px] flex-shrink-0 rounded-lg object-cover" />
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate text-sm font-bold text-[#172a37]" x-text="item.title"></h3>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-[#7b8a82]">
                                    <span class="font-bold text-[#0f766e]">Ch <span x-text="item.chapterNumber || '?'"></span></span>
                                    <span x-text="rel(item)"></span>
                                </div>
                            </div>
                            <span class="grid h-9 w-9 flex-shrink-0 place-items-center rounded-full bg-[#0f766e] text-white transition group-hover:bg-[#115e59]">
                                <span class="material-symbols-rounded text-[18px]">menu_book</span>
                            </span>
                        </a>
                    </template>
                </div>
            </section>
        @endif

        {{-- ===== FILTERS ===== --}}
        <section class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Manga categories">
                    @php
                        $categories = ['popular' => 'Popular', 'trending' => 'Trending', 'ongoing' => 'Ongoing', 'completed' => 'Completed'];
                    @endphp
                    @foreach($categories as $key => $label)
                        <a href="{{ route('manga.index', array_merge(request()->except(['category', 'page']), ['category' => $key])) }}"
                           role="tab"
                           @if($currentCategory === $key) aria-selected="true" @endif
                           class="inline-flex h-8 items-center rounded-full px-3.5 text-xs font-bold transition {{ $currentCategory === $key ? 'bg-[#0f766e] text-white shadow-sm' : 'border border-[#dcd2bd] bg-white/70 text-[#4c5b54] hover:border-[#0f766e]/40 hover:text-[#0f766e]' }}">
                            {{ $label }}
                        </a>
                    @endforeach
                </div>

                <div class="relative" x-data="{ genreOpen: false }" @keydown.escape="genreOpen = false" @click.away="genreOpen = false">
                    <button type="button" @click="genreOpen = !genreOpen"
                            class="inline-flex h-9 items-center gap-2 rounded-full border border-[#dcd2bd] bg-white/80 px-4 text-xs font-bold text-[#172a37] shadow-sm transition hover:border-[#0f766e]/50">
                        <span class="material-symbols-rounded text-base text-[#0f766e]">tune</span>
                        Genre: <span class="text-[#0f766e]">{{ $currentGenre ?: 'All' }}</span>
                        <span class="material-symbols-rounded text-base transition-transform" :class="genreOpen ? 'rotate-180' : ''">expand_more</span>
                    </button>

                    <div x-show="genreOpen" x-cloak
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         x-transition:leave="transition ease-in duration-100"
                         x-transition:leave-start="opacity-100"
                         x-transition:leave-end="opacity-0"
                         class="absolute left-0 z-50 mt-2 w-64 max-w-[calc(100vw-2rem)] rounded-2xl border border-[#dcd2bd] bg-white p-2 shadow-2xl sm:left-auto sm:right-0 sm:w-72">
                        <a href="{{ route('manga.index', array_merge(request()->query(), ['genre' => null, 'page' => 1])) }}"
                           class="flex items-center justify-between rounded-xl px-3 py-2 text-xs font-semibold transition {{ empty($currentGenre) ? 'bg-[#0f766e] text-white' : 'text-[#4c5b54] hover:bg-[#f6f1e8]' }}">
                            All genres
                            @if(empty($currentGenre))<span class="material-symbols-rounded text-sm">check</span>@endif
                        </a>
                        <div class="custom-scroll mt-1 grid max-h-64 grid-cols-2 gap-1 overflow-y-auto pr-1">
                            @foreach($genres as $g)
                                <a href="{{ route('manga.index', array_merge(request()->query(), ['genre' => $g, 'page' => 1])) }}"
                                   class="flex items-center justify-between rounded-lg px-2.5 py-1.5 text-[11px] font-semibold transition {{ $currentGenre === $g ? 'bg-[#0f766e] text-white' : 'text-[#37474f] hover:bg-[#0f766e]/10 hover:text-[#0f766e]' }}">
                                    <span class="truncate">{{ $g }}</span>
                                    @if($currentGenre === $g)<span class="material-symbols-rounded text-xs">check</span>@endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            @if($search !== '')
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-[#dcd2bd] bg-white/80 px-4 py-3">
                    <div class="flex items-center gap-2 text-sm text-[#263640]">
                        <span class="material-symbols-rounded text-[#0f766e]">search</span>
                        <span>Results for: <strong class="text-[#0f766e]">&ldquo;{{ $search }}&rdquo;</strong></span>
                    </div>
                    <a href="{{ route('manga.index') }}" class="text-xs font-bold uppercase tracking-wider text-[#ea580c] hover:underline">Reset</a>
                </div>
            @endif
        </section>

        {{-- ===== LATEST GRID ===== --}}
        <section class="space-y-5">
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div>
                    <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-[#84948a]">Latest updates</p>
                    <h2 class="font-display text-xl font-bold tracking-tight text-[#172a37] sm:text-2xl">
                        @if($search !== '')
                            Search results
                        @elseif($currentCategory === 'trending')
                            Trending manga
                        @elseif($currentCategory === 'ongoing')
                            Ongoing manga
                        @elseif($currentCategory === 'completed')
                            Completed manga
                        @else
                            Popular this week
                        @endif
                    </h2>
                    <p class="mt-1 text-xs text-[#6f7e75]">{{ number_format((int) $total) }} titles &middot; page {{ $page }} of {{ $totalPages }}</p>
                </div>
            </div>

            @if(empty($mangas))
                <div class="rounded-3xl border border-[#dcd2bd] bg-white/70 px-6 py-16 text-center">
                    <span class="material-symbols-rounded text-5xl text-[#c1cbbb]">menu_book</span>
                    <h3 class="mt-2 font-display text-lg font-bold text-[#172a37]">No results</h3>
                    <p class="mx-auto mt-1 max-w-md text-xs text-[#6f7e75]">Try another keyword or pick a different category.</p>
                    <a href="{{ route('manga.index') }}" class="mt-4 inline-flex h-9 items-center rounded-xl bg-[#0f766e] px-5 text-xs font-bold text-white">Back to catalog</a>
                </div>
            @else
                <div class="grid grid-cols-2 gap-x-3 gap-y-6 sm:grid-cols-3 sm:gap-x-4 md:grid-cols-4 lg:grid-cols-6">
                    @foreach($mangas as $item)
                        @include('manga.partials.card', ['item' => $item])
                    @endforeach
                </div>
            @endif
        </section>

        {{-- ===== TRENDING RAIL ===== --}}
        @php
            $trendingManga = array_slice(array_reverse($mangas), 0, 10);
        @endphp
        @if(count($trendingManga) > 1)
            <section class="space-y-4">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-[#84948a]">Trending</p>
                        <h2 class="font-display text-xl font-bold tracking-tight text-[#172a37] sm:text-2xl">Trending this week</h2>
                    </div>
                    <a href="{{ route('manga.index', ['category' => 'trending']) }}" class="text-xs font-bold text-[#0f766e] hover:underline">Explore all &rarr;</a>
                </div>
                <div class="rail-x no-scrollbar pl-1 pr-1">
                    @foreach($trendingManga as $i => $item)
                        <div class="w-[128px] sm:w-[150px]">
                            @include('manga.partials.card', ['item' => $item, 'rank' => $i + 1])
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ===== PAGINATION ===== --}}
        @if(!empty($mangas) && $totalPages > 1)
            @include('partials.pagination', ['page' => $page, 'totalPages' => $totalPages, 'routeName' => 'manga.index'])
        @endif
    </div>
    <script>
    window.prefetchShow = function(url) {
        if (!url || window.__prefetchedUrls?.[url]) return;
        window.__prefetchedUrls = window.__prefetchedUrls || {};
        window.__prefetchedUrls[url] = true;

        // Warm the detail page itself.
        const link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = url;
        document.head.appendChild(link);

        // Also warm the (possibly cold) chapter aggregation in the background,
        // so the skeleton on the detail page resolves almost immediately.
        // Use exactly the same per-language URLs the detail page requests, so
        // its fetches are already edge-cached. Fire-and-forget.
        const version = encodeURIComponent(@js($chaptersVersion));
        for (const lang of ['id', 'en']) {
            try {
                fetch(url + '/chapters.json?v=' + version + '&lang=' + lang, {
                    headers: { 'Accept': 'application/json' },
                    cache: 'default',
                    priority: 'low',
                }).catch(() => {});
            } catch (e) {}
        }
    };
    </script>
</x-manga-layout>
