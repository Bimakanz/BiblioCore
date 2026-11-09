<x-manga-layout title="BiblioManga — Read Manga & Comics Online for Free">
    <div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 space-y-8">

        <!-- Hero Section -->
        <section class="relative rounded-[2.5rem] border border-[#dcd2bd] bg-gradient-to-br from-white/95 via-[#fbf9f4]/90 to-[#f6ecda]/80 p-8 sm:p-12 shadow-[0_20px_60px_-35px_rgba(15,87,82,0.35)]">
            <div class="max-w-4xl space-y-4">
                <div class="inline-flex items-center gap-2 rounded-full bg-[#0f766e]/10 px-3.5 py-1 text-xs font-bold uppercase tracking-[0.25em] text-[#0f766e]">
                    <span class="material-symbols-rounded text-sm">auto_stories</span>
                    Open Manga Reader
                </div>
                <h1 class="text-3xl sm:text-5xl font-extrabold tracking-tight text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">
                    Read Manga & Comics Online <span class="text-[#0f766e]">Without Limits</span>
                </h1>
                <p class="text-sm sm:text-base leading-relaxed text-[#4c5b54]">
                    Discover thousands of the most popular, trending, and latest manga, manhwa, and manhua titles.
                    Everything can be read instantly for free without registration or login.
                </p>

                <!-- Filter Bar: Category & Genre -->
                <div class="pt-3 flex flex-wrap items-center justify-between gap-y-3 gap-x-4 border-t border-[#e0d9c8]/70"
                     x-data="{ genreOpen: false }">
                    
                    <!-- Category Tabs (No Icons) -->
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-xs font-bold uppercase tracking-wider text-[#6f7e75] mr-1">Category:</span>
                        <a href="{{ route('manga.index', array_merge(request()->except(['category', 'page']), ['category' => 'popular'])) }}"
                           class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition border {{ $currentCategory === 'popular' ? 'bg-[#0f766e] text-white border-[#0f766e] shadow-sm' : 'bg-white text-[#4c5b54] border-[#dcd2bd] hover:border-[#0f766e]/40' }}">
                            Popular
                        </a>
                        <a href="{{ route('manga.index', array_merge(request()->except(['category', 'page']), ['category' => 'trending'])) }}"
                           class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition border {{ $currentCategory === 'trending' ? 'bg-[#0f766e] text-white border-[#0f766e] shadow-sm' : 'bg-white text-[#4c5b54] border-[#dcd2bd] hover:border-[#0f766e]/40' }}">
                            Trending
                        </a>
                        <a href="{{ route('manga.index', array_merge(request()->except(['category', 'page']), ['category' => 'ongoing'])) }}"
                           class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition border {{ $currentCategory === 'ongoing' ? 'bg-[#0f766e] text-white border-[#0f766e] shadow-sm' : 'bg-white text-[#4c5b54] border-[#dcd2bd] hover:border-[#0f766e]/40' }}">
                            Ongoing
                        </a>
                        <a href="{{ route('manga.index', array_merge(request()->except(['category', 'page']), ['category' => 'completed'])) }}"
                           class="rounded-full px-3.5 py-1.5 text-xs font-semibold transition border {{ $currentCategory === 'completed' ? 'bg-[#0f766e] text-white border-[#0f766e] shadow-sm' : 'bg-white text-[#4c5b54] border-[#dcd2bd] hover:border-[#0f766e]/40' }}">
                            Completed
                        </a>
                    </div>

                    <!-- Custom Genre Dropdown matching Website Editorial Theme -->
                    <div class="relative z-30">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-bold uppercase tracking-wider text-[#6f7e75]">Genre:</span>
                            
                            <!-- Trigger Button -->
                            <button type="button"
                                    @click="genreOpen = !genreOpen"
                                    class="inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-xs font-semibold transition-all shadow-sm focus:outline-none {{ $currentGenre ? 'bg-[#0f766e] text-white border-[#0f766e] shadow-[#0f766e]/20' : 'bg-white text-[#172a37] border-[#dcd2bd] hover:border-[#0f766e] hover:bg-[#fbf9f4]' }}">
                                <span class="material-symbols-rounded text-sm {{ $currentGenre ? 'text-white' : 'text-[#0f766e]' }}">tune</span>
                                <span>{{ $currentGenre ? $currentGenre : 'All Genres' }}</span>
                                <span class="material-symbols-rounded text-base transition-transform duration-200"
                                      :class="genreOpen ? 'rotate-180' : ''">expand_more</span>
                            </button>

                            @if($currentGenre)
                                <a href="{{ route('manga.index', array_merge(request()->query(), ['genre' => null, 'page' => 1])) }}"
                                   class="inline-flex h-7 w-7 items-center justify-center rounded-full bg-[#eaddc2] text-[#5f6b63] transition hover:bg-[#ea580c] hover:text-white"
                                   title="Remove genre filter">
                                    <span class="material-symbols-rounded text-sm">close</span>
                                </a>
                            @endif
                        </div>

                        <!-- Dropdown Popover Card -->
                        <div x-cloak
                             x-show="genreOpen"
                             @click.away="genreOpen = false"
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                             x-transition:leave="transition ease-in duration-100"
                             x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                             x-transition:leave-end="opacity-0 translate-y-2 scale-95"
                             class="absolute left-0 sm:left-auto sm:right-0 top-full mt-2 z-50 w-72 sm:w-80 rounded-3xl border border-[#dcd2bd] bg-white/95 backdrop-blur-xl p-4 shadow-[0_25px_60px_-15px_rgba(15,118,110,0.4)] ring-1 ring-black/10 space-y-3">
                            
                            <!-- Header inside Popover -->
                            <div class="flex items-center justify-between border-b border-[#e0d9c8] pb-2 px-1">
                                <span class="text-xs font-bold text-[#172a37] uppercase tracking-wider" style="font-family: 'Space Grotesk', sans-serif;">Select Genre</span>
                                @if($currentGenre)
                                    <a href="{{ route('manga.index', array_merge(request()->query(), ['genre' => null, 'page' => 1])) }}"
                                       class="text-[11px] font-bold text-[#ea580c] hover:underline">
                                        Reset Filter
                                    </a>
                                @endif
                            </div>

                            <!-- "All Genres" Option -->
                            <a href="{{ route('manga.index', array_merge(request()->query(), ['genre' => null, 'page' => 1])) }}"
                               class="flex items-center justify-between w-full rounded-2xl px-3 py-2 text-xs font-semibold transition {{ empty($currentGenre) ? 'bg-[#0f766e] text-white shadow-sm' : 'bg-[#fbf9f4] text-[#4c5b54] hover:bg-[#0f766e]/10 hover:text-[#0f766e]' }}">
                                <span>All Genres</span>
                                @if(empty($currentGenre))
                                    <span class="material-symbols-rounded text-sm">check</span>
                                @endif
                            </a>

                            <!-- 2-Column Grid of Genres -->
                            <div class="grid grid-cols-2 gap-1.5 max-h-56 overflow-y-auto pr-1 custom-scroll">
                                @foreach($genres as $g)
                                    <a href="{{ route('manga.index', array_merge(request()->query(), ['genre' => $g, 'page' => 1])) }}"
                                       class="flex items-center justify-between rounded-xl px-2.5 py-1.5 text-xs font-semibold transition {{ $currentGenre === $g ? 'bg-[#0f766e] text-white shadow-sm' : 'text-[#37474f] hover:bg-[#0f766e]/10 hover:text-[#0f766e]' }}">
                                        <span class="truncate">{{ $g }}</span>
                                        @if($currentGenre === $g)
                                            <span class="material-symbols-rounded text-xs flex-shrink-0">check</span>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Search Status / Filter Info -->
        @if($search !== '')
            <div class="flex items-center justify-between rounded-2xl border border-[#dcd2bd] bg-white p-4">
                <div class="flex items-center gap-2 text-sm text-[#263640]">
                    <span class="material-symbols-rounded text-[#0f766e]">search</span>
                    <span>Search results for: <strong class="text-[#0f766e]">"{{ $search }}"</strong></span>
                </div>
                <a href="{{ route('manga.index') }}" class="text-xs font-bold uppercase tracking-wider text-[#ea580c] hover:underline">
                    Reset Search
                </a>
            </div>
        @endif

        <!-- Manga Cards Grid -->
        <section class="space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">
                        @if($search !== '')
                            Search Results
                        @elseif($currentCategory === 'trending')
                            Trending Manga
                        @elseif($currentCategory === 'ongoing')
                            Ongoing Manga
                        @elseif($currentCategory === 'completed')
                            Completed Manga
                        @else
                            Popular Manga
                        @endif
                    </h2>
                    <p class="text-xs text-[#6f7e75] mt-0.5">Showing curated manga from MangaDex & scanlation groups</p>
                </div>
                <div class="text-xs text-[#6f7e75]">
                    Page {{ $page }} of {{ $totalPages }}
                </div>
            </div>

            @if(empty($mangas))
                <div class="rounded-3xl border border-[#dcd2bd] bg-white p-16 text-center space-y-3">
                    <span class="material-symbols-rounded text-5xl text-[#c1cbbb]">menu_book</span>
                    <h3 class="text-lg font-bold text-[#172a37]">No results found</h3>
                    <p class="text-xs text-[#6f7e75] max-w-md mx-auto">
                        Try another keyword or choose a different category to find the comic you are looking for.
                    </p>
                    <a href="{{ route('manga.index') }}" class="inline-block mt-3 rounded-full bg-[#0f766e] px-6 py-2 text-xs font-semibold text-white">
                        Back to All Manga
                    </a>
                </div>
            @else
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 sm:gap-6">
                    @foreach($mangas as $item)
                        <a href="{{ route('manga.show', $item['id']) }}"
                           @mouseenter.once="prefetchShow('{{ route('manga.show', $item['id']) }}')"
                           @touchstart.passive.once="prefetchShow('{{ route('manga.show', $item['id']) }}')"
                           class="group relative flex flex-col overflow-hidden rounded-[1.75rem] border border-[#dcd2bd] bg-white/90 p-3 shadow-sm transition-all duration-300 hover:-translate-y-1.5 hover:shadow-xl hover:border-[#0f766e] cursor-pointer">
                            
                            <!-- Cover Thumbnail -->
                            <div class="relative aspect-[3/4] w-full overflow-hidden rounded-2xl bg-[#eaddc2]">
                                @if($item['coverUrl'])
                                    <img src="{{ $item['coverUrl'] }}" alt="{{ $item['title'] }}"
                                         loading="lazy"
                                         referrerpolicy="no-referrer"
                                         class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                                         onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                                    <div class="hidden h-full w-full flex-col items-center justify-center bg-gradient-to-br from-[#164e63] via-[#0f766e] to-[#134e4a] p-3 text-center text-white">
                                        <span class="material-symbols-rounded text-3xl text-[#fde68a] mb-1">auto_stories</span>
                                        <span class="text-[11px] font-bold leading-snug line-clamp-3">{{ $item['title'] }}</span>
                                    </div>
                                @else
                                    <div class="flex h-full w-full flex-col items-center justify-center bg-gradient-to-br from-[#164e63] via-[#0f766e] to-[#134e4a] p-3 text-center text-white">
                                        <span class="material-symbols-rounded text-3xl text-[#fde68a] mb-1">auto_stories</span>
                                        <span class="text-[11px] font-bold leading-snug line-clamp-3">{{ $item['title'] }}</span>
                                    </div>
                                @endif

                                <!-- Status & Format Badges -->
                                <div class="absolute top-2 left-2 flex flex-col gap-1 items-start">
                                    <span class="inline-flex items-center rounded-full bg-black/60 backdrop-blur-sm px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider text-white">
                                        {{ $item['status'] }}
                                    </span>
                                    @if(!empty($item['format']) && strtoupper($item['format']) === 'NOVEL')
                                        <span class="inline-flex items-center rounded-full bg-amber-500/90 backdrop-blur-sm px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider text-white">
                                            Novel
                                        </span>
                                    @endif
                                </div>

                                <!-- Bookmark icon toggle -->
                                <button type="button"
                                        @click.prevent.stop="toggleFav({ id: '{{ $item['id'] }}', title: '{{ addslashes($item['title']) }}', coverUrl: '{{ $item['coverUrl'] }}', status: '{{ $item['status'] }}' })"
                                        class="absolute top-2 right-2 rounded-full p-1.5 backdrop-blur-md transition z-10"
                                        :class="isFav('{{ $item['id'] }}') ? 'bg-[#f97316] text-white shadow-md' : 'bg-black/40 text-white/80 hover:bg-black/60'">
                                    <span class="material-symbols-rounded text-sm block">bookmark</span>
                                </button>
                            </div>

                            <!-- Manga Info -->
                            <div class="mt-3 flex flex-1 flex-col justify-between space-y-1">
                                <div>
                                    <h3 class="line-clamp-2 text-xs sm:text-sm font-bold text-[#172a37] group-hover:text-[#0f766e] transition-colors" title="{{ $item['title'] }}">
                                        {{ $item['title'] }}
                                    </h3>
                                    
                                    @if(!empty($item['tags']))
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach(array_slice($item['tags'], 0, 2) as $tag)
                                                <span class="text-[9px] font-medium text-[#6f7e75] bg-[#f6ecda]/60 rounded-md px-1.5 py-0.5">
                                                    {{ $tag }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>

                        </a>
                    @endforeach
                </div>

                <!-- Pagination -->
                <div class="mt-12 flex items-center justify-center gap-3">
                    @if($page > 1)
                        <a href="{{ route('manga.index', array_merge(request()->query(), ['page' => $page - 1])) }}"
                           class="inline-flex items-center gap-1 rounded-full border border-[#dcd2bd] bg-white px-5 py-2.5 text-xs font-bold uppercase tracking-wider text-[#172a37] transition hover:border-[#0f766e] hover:text-[#0f766e] shadow-sm">
                            <span class="material-symbols-rounded text-base">arrow_back</span>
                            Previous
                        </a>
                    @endif

                    <div class="rounded-full border border-[#dcd2bd] bg-white/70 px-4 py-2 text-xs font-semibold text-[#5f6b63]">
                        Page {{ $page }} / {{ $totalPages }}
                    </div>

                    @if($page < $totalPages)
                        <a href="{{ route('manga.index', array_merge(request()->query(), ['page' => $page + 1])) }}"
                           class="inline-flex items-center gap-1 rounded-full border border-[#dcd2bd] bg-white px-5 py-2.5 text-xs font-bold uppercase tracking-wider text-[#172a37] transition hover:border-[#0f766e] hover:text-[#0f766e] shadow-sm">
                            Next
                            <span class="material-symbols-rounded text-base">arrow_forward</span>
                        </a>
                    @endif
                </div>
            @endif
        </section>

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
