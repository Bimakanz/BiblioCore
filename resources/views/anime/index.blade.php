<x-manga-layout title="BiblioAnime — Watch Anime with English Subs for Free">
    <div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 space-y-10">

        @if($mode === 'home')
            {{-- ===== HERO SLIDER: Trending/Popular with HD images ===== --}}
            @if(!empty($hero))
                <section x-data="{ active: 0, total: {{ count($hero) }}, timer: null, next() { this.active = (this.active + 1) % this.total }, start() { if (!this.timer && this.total > 1) this.timer = setInterval(() => this.next(), 6000) }, stop() { if (this.timer) { clearInterval(this.timer); this.timer = null } } }" x-init="start()" @mouseenter="stop()" @mouseleave="start()"
                         class="relative h-[340px] overflow-hidden rounded-[2rem] bg-[#172a37] shadow-[0_25px_60px_-30px_rgba(23,42,55,0.7)] sm:h-[460px] sm:rounded-[2.5rem]">
                    @foreach($hero as $i => $slide)
                        <div class="absolute inset-0 transition-opacity duration-700" :class="active === {{ $i }} ? 'opacity-100 z-10' : 'opacity-0 z-0 pointer-events-none'">
                            <img src="{{ $slide['heroUrl'] ?: ($slide['bannerUrl'] ?: $slide['coverUrl']) }}" alt="{{ $slide['title'] }}" @if($i > 0) loading="lazy" @else fetchpriority="high" @endif decoding="async"
                                 class="absolute inset-0 h-full w-full object-cover opacity-60" />
                            <div class="absolute inset-0 bg-gradient-to-r from-[#172a37] via-[#172a37]/70 to-transparent"></div>
                            <div class="absolute inset-0 bg-gradient-to-t from-[#172a37]/90 via-transparent to-transparent"></div>
                            <div class="relative flex h-full flex-col justify-end gap-3 p-6 pb-14 sm:gap-4 sm:p-12 sm:pb-16">
                                <div class="inline-flex w-fit items-center gap-2 rounded-full bg-[#f97316] px-3.5 py-1 text-[11px] font-bold uppercase tracking-[0.2em] text-white">
                                    <span class="material-symbols-rounded text-sm">local_fire_department</span>
                                    Trending #{{ $i + 1 }}
                                </div>
                                <h1 class="max-w-2xl text-3xl sm:text-5xl font-extrabold tracking-tight text-white" style="font-family: 'Space Grotesk', sans-serif;">
                                    {{ $slide['title'] }}
                                </h1>
                                <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-white/85">
                                    @if($slide['score'])<span class="inline-flex items-center gap-1 rounded-full bg-white/10 px-2.5 py-1"><span class="material-symbols-rounded text-sm text-[#fbbf24]">star</span>{{ $slide['score'] }}</span>@endif
                                    @if($slide['year'])<span class="rounded-full bg-white/10 px-2.5 py-1">{{ $slide['year'] }}</span>@endif
                                    <span class="rounded-full bg-white/10 px-2.5 py-1">{{ $slide['format'] }}</span>
                                    @if($slide['episodes'])<span class="rounded-full bg-white/10 px-2.5 py-1">{{ $slide['episodes'] }} Episodes</span>@endif
                                    <span class="rounded-full bg-[#0f766e] px-2.5 py-1 text-white">{{ $slide['status'] }}</span>
                                </div>
                                <p class="max-w-2xl text-sm leading-relaxed text-white/75 line-clamp-2">{{ $slide['description'] }}</p>
                                <div class="flex flex-wrap gap-3 pt-1">
                                    <a href="{{ route('anime.show', ['id' => $slide['id']]) }}" class="inline-flex items-center gap-2 rounded-full bg-white px-5 py-2.5 text-sm font-bold text-[#172a37] transition hover:bg-[#f97316] hover:text-white">
                                        <span class="material-symbols-rounded">play_circle</span> Watch Now
                                    </a>
                                    <a href="{{ route('anime.index', ['category' => 'trending']) }}" class="inline-flex items-center gap-2 rounded-full border border-white/40 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-white/10">
                                        All Trending
                                    </a>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </section>
            @endif

            {{-- ===== CONTINUE WATCHING (localStorage) ===== --}}
            <section x-data="{ items: JSON.parse(localStorage.getItem('biblioanime_history') || '[]'), fmt(s) { s = Math.max(0, Math.floor(s || 0)); const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), ss = String(s % 60).padStart(2, '0'); return h > 0 ? h + ':' + String(m).padStart(2, '0') + ':' + ss : m + ':' + ss; } }" x-show="items.length > 0" x-cloak>
                <div class="mb-4 flex items-end justify-between">
                    <h2 class="text-xl sm:text-2xl font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">Continue Watching</h2>
                    <span class="text-xs font-semibold text-[#84948a]">Your last position is saved automatically</span>
                </div>
                <div class="flex gap-4 overflow-x-auto pb-2 custom-scroll snap-x">
                    <template x-for="item in items" :key="item.id">
                        <a :href="'/anime/' + item.id + '/watch?ep=' + encodeURIComponent(item.episodeId || '') + '&n=' + encodeURIComponent(item.episodeNumber || 1) + '&t=' + Math.max(0, Math.floor((item.positionSec || 0) - 5))"
                           class="group w-[280px] sm:w-[320px] flex-shrink-0 snap-start overflow-hidden rounded-3xl border border-[#e0d9c8] bg-white shadow-sm transition hover:shadow-lg">
                            <div class="relative aspect-video overflow-hidden bg-[#172a37]">
                                <img :src="item.bannerUrl || item.coverUrl" class="h-full w-full object-cover transition duration-500 group-hover:scale-105" />
                                <div class="absolute inset-0 flex items-center justify-center bg-black/25 opacity-0 transition group-hover:opacity-100">
                                    <span class="material-symbols-rounded text-5xl text-white">play_circle</span>
                                </div>
                                <span class="absolute bottom-2 right-2 rounded-full bg-black/70 px-2 py-0.5 text-[10px] font-bold text-white" x-text="fmt(item.positionSec) + ' / ' + fmt(item.durationSec)"></span>
                            </div>
                            <div class="space-y-2 p-4">
                                <h3 class="truncate text-sm font-bold text-[#172a37]" x-text="item.title"></h3>
                                <div class="flex items-center justify-between text-[11px] font-semibold text-[#84948a]">
                                    <span>Episode <span x-text="item.episodeNumber"></span></span>
                                    <span x-text="item.durationSec > 0 ? Math.round(100 * (item.positionSec || 0) / item.durationSec) + '% watched' : ''"></span>
                                </div>
                                <div class="h-1.5 w-full rounded-full bg-[#eaddc2] overflow-hidden">
                                    <div class="h-full rounded-full bg-[#f97316]" :style="'width: ' + (item.durationSec > 0 ? Math.min(100, Math.round(100 * (item.positionSec || 0) / item.durationSec)) : 0) + '%'"></div>
                                </div>
                            </div>
                        </a>
                    </template>
                </div>
            </section>

            {{-- ===== TRENDING RAIL ===== --}}
            @if(!empty($trending))
                <section>
                    <div class="mb-4 flex items-end justify-between">
                        <h2 class="text-xl sm:text-2xl font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">Trending Now</h2>
                        <a href="{{ route('anime.index', ['category' => 'trending']) }}" class="text-xs font-bold text-[#0f766e] hover:underline">View All &rarr;</a>
                    </div>
                    <div class="flex gap-4 overflow-x-auto pb-2 custom-scroll snap-x">
                        @foreach($trending as $anime)
                            <div class="w-[280px] sm:w-[320px] flex-shrink-0 snap-start">
                                @include('anime.partials.card-wide', ['anime' => $anime])
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- ===== SEASON GRID ===== --}}
            @if(!empty($seasonPopular))
                <section>
                    <div class="mb-4 flex items-end justify-between">
                        <div>
                            <h2 class="text-xl sm:text-2xl font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">Popular This Season</h2>
                            <p class="text-xs font-semibold text-[#84948a]">{{ ucfirst(strtolower($seasonInfo['season'])) }} {{ $seasonInfo['year'] }} &middot; latest &amp; most popular releases</p>
                        </div>
                        <a href="{{ route('anime.index', ['category' => 'season']) }}" class="text-xs font-bold text-[#0f766e] hover:underline">View All &rarr;</a>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($seasonPopular as $anime)
                            @include('anime.partials.card-wide', ['anime' => $anime])
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- ===== TOP RATED GRID ===== --}}
            @if(!empty($topRated))
                <section>
                    <div class="mb-4 flex items-end justify-between">
                        <h2 class="text-xl sm:text-2xl font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">Highest Rated of All Time</h2>
                        <a href="{{ route('anime.index', ['category' => 'top']) }}" class="text-xs font-bold text-[#0f766e] hover:underline">View All &rarr;</a>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach(array_slice($topRated, 0, 6) as $anime)
                            @include('anime.partials.card-wide', ['anime' => $anime])
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- ===== UPCOMING RAIL ===== --}}
            @if(!empty($upcoming))
                <section>
                    <div class="mb-4 flex items-end justify-between">
                        <h2 class="text-xl sm:text-2xl font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">Coming Soon</h2>
                        <a href="{{ route('anime.index', ['category' => 'upcoming']) }}" class="text-xs font-bold text-[#0f766e] hover:underline">View All &rarr;</a>
                    </div>
                    <div class="flex gap-4 overflow-x-auto pb-2 custom-scroll snap-x">
                        @foreach($upcoming as $anime)
                            <div class="w-[280px] sm:w-[320px] flex-shrink-0 snap-start">
                                @include('anime.partials.card-wide', ['anime' => $anime])
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif

        @else
            {{-- ===== BROWSE / SEARCH / CATEGORY ===== --}}
            <section class="space-y-5">
                <div class="space-y-1">
                    <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">
                        @if($search !== '')
                            Results for &ldquo;{{ $search }}&rdquo;
                        @else
                            Anime Catalog
                        @endif
                    </h1>
                    <p class="text-sm text-[#6f7e75]">{{ number_format($total) }} titles found</p>
                </div>

                @php $catLabels = ['popular' => 'Popular', 'trending' => 'Trending', 'season' => 'This Season', 'top' => 'Highest Rated', 'ongoing' => 'Ongoing', 'completed' => 'Finished', 'upcoming' => 'Coming Soon']; @endphp
                <div class="flex flex-wrap items-center gap-2.5">
                    {{-- Category: single dropdown button --}}
                    <div class="relative" x-data="{ open: false }" @keydown.escape="open = false">
                        <button type="button" @click="open = !open" class="inline-flex items-center gap-2 rounded-full border border-[#dcd2bd] bg-white px-4 py-2 text-xs font-bold text-[#172a37] shadow-sm transition hover:border-[#0f766e]/50">
                            <span class="material-symbols-rounded text-base text-[#0f766e]">tune</span>
                            Category: <span class="text-[#0f766e]">{{ $catLabels[$currentCategory] ?? 'Popular' }}</span>
                            <span class="material-symbols-rounded text-base transition-transform duration-200" :class="open ? 'rotate-180' : ''">expand_more</span>
                        </button>
                        <div x-show="open" x-cloak @click.outside="open = false" class="absolute left-0 z-30 mt-2 w-52 overflow-hidden rounded-2xl border border-[#e0d9c8] bg-white p-1.5 shadow-[0_20px_45px_-18px_rgba(23,42,55,0.45)]">
                            @foreach($catLabels as $key => $label)
                                <a href="{{ route('anime.index', array_merge(request()->except(['category', 'page']), ['category' => $key])) }}" class="flex items-center justify-between rounded-xl px-3 py-2 text-xs font-semibold transition {{ $currentCategory === $key && $search === '' ? 'bg-[#0f766e] text-white' : 'text-[#4c5b54] hover:bg-[#f6f1e8]' }}">
                                    {{ $label }}
                                    @if($currentCategory === $key && $search === '')<span class="material-symbols-rounded text-sm">check</span>@endif
                                </a>
                            @endforeach
                        </div>
                    </div>

                    {{-- Genre: single dropdown button --}}
                    <div class="relative" x-data="{ open: false }" @keydown.escape="open = false">
                        <button type="button" @click="open = !open" class="inline-flex items-center gap-2 rounded-full border border-[#dcd2bd] bg-white px-4 py-2 text-xs font-bold text-[#172a37] shadow-sm transition hover:border-[#0f766e]/50">
                            <span class="material-symbols-rounded text-base text-[#0f766e]">theater_comedy</span>
                            Genre: <span class="text-[#0f766e]">{{ $currentGenre ?: 'All Genres' }}</span>
                            <span class="material-symbols-rounded text-base transition-transform duration-200" :class="open ? 'rotate-180' : ''">expand_more</span>
                        </button>
                        <div x-show="open" x-cloak @click.outside="open = false" class="absolute left-0 z-30 mt-2 w-56 overflow-hidden rounded-2xl border border-[#e0d9c8] bg-white p-1.5 shadow-[0_20px_45px_-18px_rgba(23,42,55,0.45)]">
                            <div class="custom-scroll max-h-72 overflow-y-auto">
                                <a href="{{ route('anime.index', array_merge(request()->except(['genre', 'page']), [])) }}" class="flex items-center justify-between rounded-xl px-3 py-2 text-xs font-semibold transition {{ !$currentGenre ? 'bg-[#0f766e] text-white' : 'text-[#4c5b54] hover:bg-[#f6f1e8]' }}">
                                    All Genres
                                    @if(!$currentGenre)<span class="material-symbols-rounded text-sm">check</span>@endif
                                </a>
                                @foreach($genres as $genre)
                                    <a href="{{ route('anime.index', array_merge(request()->except(['page']), ['genre' => $genre])) }}" class="flex items-center justify-between rounded-xl px-3 py-2 text-xs font-semibold transition {{ $currentGenre === $genre ? 'bg-[#0f766e] text-white' : 'text-[#4c5b54] hover:bg-[#f6f1e8]' }}">
                                        {{ $genre }}
                                        @if($currentGenre === $genre)<span class="material-symbols-rounded text-sm">check</span>@endif
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

                @if(empty($animes))
                    <div class="rounded-3xl border border-dashed border-[#dcd2bd] bg-white/60 py-16 text-center">
                        <span class="material-symbols-rounded text-4xl text-[#c1cbbb]">search_off</span>
                        <p class="mt-2 text-sm font-medium text-[#6f7e75]">No matching anime found. Try another keyword or filter.</p>
                    </div>
                @else
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($animes as $anime)
                            @include('anime.partials.card-wide', ['anime' => $anime])
                        @endforeach
                    </div>

                    <div class="flex items-center justify-between pt-2">
                        @if($page > 1)
                            <a href="{{ route('anime.index', array_merge(request()->query(), ['page' => $page - 1])) }}" class="inline-flex items-center gap-1 rounded-full border border-[#dcd2bd] bg-white px-4 py-2 text-xs font-bold text-[#172a37] transition hover:border-[#0f766e]/50">
                                <span class="material-symbols-rounded text-base">arrow_back</span> Previous
                            </a>
                        @else
                            <span></span>
                        @endif
                        <span class="text-xs font-semibold text-[#84948a]">Page {{ $page }} of {{ $totalPages }}</span>
                        @if($page < $totalPages)
                            <a href="{{ route('anime.index', array_merge(request()->query(), ['page' => $page + 1])) }}" class="inline-flex items-center gap-1 rounded-full bg-[#0f766e] px-4 py-2 text-xs font-bold text-white transition hover:bg-[#115e59]">
                                Next <span class="material-symbols-rounded text-base">arrow_forward</span>
                            </a>
                        @else
                            <span></span>
                        @endif
                    </div>
                @endif
            </section>
        @endif
    </div>
</x-manga-layout>
