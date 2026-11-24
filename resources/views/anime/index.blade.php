<x-manga-layout title="BiblioAnime — Watch Anime with English Subs for Free">
    <div class="space-y-9">

        @if($mode === 'home')
            {{-- ===== HERO SLIDER: Trending/Popular with HD images ===== --}}
            @if(!empty($hero))
                <section x-data="{ active: 0, total: {{ count($hero) }}, timer: null, next() { this.active = (this.active + 1) % this.total }, start() { if (!this.timer && this.total > 1) this.timer = setInterval(() => this.next(), 6000) }, stop() { if (this.timer) { clearInterval(this.timer); this.timer = null } } }" x-init="start()" @mouseenter="stop()" @mouseleave="start()"
                         class="relative h-[320px] overflow-hidden rounded-2xl bg-[#172a37] shadow-[0_25px_60px_-30px_rgba(23,42,55,0.7)] sm:h-[420px] sm:rounded-3xl">
                    @foreach($hero as $i => $slide)
                        <div class="absolute inset-0 transition-opacity duration-700" :class="active === {{ $i }} ? 'opacity-100 z-10' : 'opacity-0 z-0 pointer-events-none'">
                            <img src="{{ $slide['coverUrl'] ?: ($slide['bannerUrl'] ?: $slide['heroUrl']) }}" alt="{{ $slide['title'] }}" @if($i > 0) loading="lazy" @else fetchpriority="high" @endif decoding="async" referrerpolicy="no-referrer"
                                 class="absolute inset-0 h-full w-full object-cover object-top opacity-75 sm:hidden" />
                            <img src="{{ $slide['heroUrl'] ?: ($slide['bannerUrl'] ?: $slide['coverUrl']) }}" alt="{{ $slide['title'] }}" @if($i > 0) loading="lazy" @else fetchpriority="high" @endif decoding="async" referrerpolicy="no-referrer"
                                 class="absolute inset-0 hidden h-full w-full object-cover object-center opacity-60 sm:block" />
                            <div class="absolute inset-0 bg-gradient-to-r from-[#172a37] via-[#172a37]/70 to-transparent"></div>
                            <div class="absolute inset-0 bg-gradient-to-t from-[#172a37]/90 via-transparent to-transparent"></div>
                            <div class="relative flex h-full flex-col justify-end gap-3 p-6 pb-14 sm:gap-4 sm:p-12 sm:pb-16">
                                <div class="inline-flex w-fit items-center gap-2 rounded-full bg-[#f97316] px-3.5 py-1 text-[11px] font-bold uppercase tracking-[0.2em] text-white">
                                    <span class="material-symbols-rounded text-sm">local_fire_department</span>
                                    Trending #{{ $i + 1 }}
                                </div>
                                <h1 class="max-w-2xl text-3xl md:text-4xl xl:text-5xl font-extrabold tracking-tight text-white" style="font-family: 'Plus Jakarta Sans', sans-serif;">
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
            <section x-data="{ items: JSON.parse(localStorage.getItem('biblioanime_history') || '[]'), fmt(s) { s = Math.max(0, Math.floor(s || 0)); const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), ss = String(s % 60).padStart(2, '0'); return h > 0 ? h + ':' + String(m).padStart(2, '0') + ':' + ss : m + ':' + ss; }, rel(ts) { const d = Number(ts) || (ts ? Date.parse(ts) : 0); if (!d) return 'just now'; const diff = Math.max(0, Date.now() - d); const m = Math.floor(diff / 60000); if (m < 1) return 'just now'; if (m < 60) return m + 'm ago'; const h = Math.floor(m / 60); if (h < 24) return h + 'h ago'; return Math.floor(h / 24) + 'd ago'; } }" x-show="items.length > 0" x-cloak>
                <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-[#84948a]">Pick up where you left off</p>
                        <h2 class="font-display text-xl font-bold text-[#172a37] sm:text-2xl">Continue Watching</h2>
                    </div>
                    <span class="text-xs font-semibold text-[#84948a]">Your last position is saved automatically</span>
                </div>
                <div class="rail-x no-scrollbar">
                    <template x-for="item in items" :key="item.id">
                        <a :href="'/anime/' + item.id + '/watch?ep=' + encodeURIComponent(item.episodeId || '') + '&n=' + encodeURIComponent(item.episodeNumber || 1) + '&t=' + Math.max(0, Math.floor((item.positionSec || 0) - 5))"
                           class="group flex w-[280px] items-center gap-3 rounded-2xl border border-[#e0d9c8] bg-white/80 p-2.5 shadow-sm transition hover:border-[#0f766e]/50 hover:shadow-md sm:w-[320px]">
                            <img :src="item.bannerUrl || item.coverUrl" alt="" loading="lazy" decoding="async"
                                 class="h-[76px] w-[120px] flex-shrink-0 rounded-lg object-cover" />
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate text-sm font-bold text-[#172a37]" x-text="item.title"></h3>
                                <div class="mt-1 flex items-center gap-2 font-mono text-[11px] text-[#84948a]">
                                    <span class="font-semibold text-[#0f766e]">EP <span x-text="item.episodeNumber"></span></span>
                                    <span class="h-1 w-1 rounded-full bg-[#c1cbbb]"></span>
                                    <span x-text="rel(item.updatedAt || item.watchedAt)"></span>
                                </div>
                                <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-[#eaddc2]">
                                    <div class="h-full rounded-full bg-[#f97316]" :style="'width: ' + (item.durationSec > 0 ? Math.min(100, Math.round(100 * (item.positionSec || 0) / item.durationSec)) : 0) + '%'"></div>
                                </div>
                                <p class="mt-1 font-mono text-[10px] text-[#84948a]" x-text="fmt(item.positionSec) + ' / ' + fmt(item.durationSec)"></p>
                            </div>
                            <span class="grid h-9 w-9 flex-shrink-0 place-items-center rounded-full bg-[#0f766e] text-white transition group-hover:bg-[#115e59]">
                                <span class="material-symbols-rounded text-[18px]">play_arrow</span>
                            </span>
                        </a>
                    </template>
                </div>
            </section>

            {{-- ===== TOP 10 LEADERBOARD: PODIUM + CHART SHELF ===== --}}
            @php
                $top10 = array_slice($trending ?? [], 0, 10);
                $podium = array_slice($top10, 0, 3);
                $rest = array_slice($top10, 3);
                $podiumLabels = ['Champion', 'Runner-up', 'Third place'];
                $podiumOrder = ['sm:order-2', 'sm:order-1', 'sm:order-3'];
            @endphp
            @if(count($top10))
                <section>
                    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-[#84948a]">Trending chart</p>
                            <h2 class="font-display text-xl font-bold tracking-tight text-[#172a37] sm:text-2xl">Top {{ count($top10) }} right now</h2>
                        </div>
                        <x-section-link :href="route('anime.index', ['category' => 'trending'])">Full ranking</x-section-link>
                    </div>

                    {{-- Podium: #1 rises above the runners-up on desktop. --}}
                    <div class="grid gap-3 sm:grid-cols-3 sm:items-end">
                        @foreach($podium as $i => $anime)
                            <a href="{{ route('anime.show', ['id' => $anime['id']]) }}"
                               class="group relative flex flex-col justify-end overflow-hidden rounded-2xl border bg-[#172a37] p-4 shadow-sm transition duration-300 hover:-translate-y-0.5 hover:shadow-[0_18px_40px_-18px_rgba(23,42,55,0.6)] sm:rounded-3xl sm:p-5 {{ $podiumOrder[$i] ?? '' }} {{ $i === 0 ? 'min-h-[220px] border-[#fbbf24]/70 ring-1 ring-[#fbbf24]/35 sm:min-h-[320px]' : 'min-h-[200px] border-[#e0d9c8] sm:min-h-[244px]' }}">
                                <img src="{{ $anime['bannerUrl'] ?: $anime['coverUrl'] }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                                     class="absolute inset-0 h-full w-full object-cover opacity-70 transition duration-700 group-hover:scale-105 group-hover:opacity-85" />
                                <div class="absolute inset-0 bg-gradient-to-t from-[#0b1a23] via-[#0b1a23]/72 to-[#0b1a23]/5"></div>

                                <span aria-hidden="true"
                                      class="pointer-events-none absolute -right-1 -top-3 select-none font-display text-[88px] font-extrabold leading-none tracking-tighter {{ $i === 0 ? 'text-[#fbbf24]/15' : 'text-white/10' }} sm:-top-4 sm:text-[108px]">
                                    {{ $i + 1 }}
                                </span>

                                <div class="relative flex items-center gap-2">
                                    <span class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-1.5 font-mono text-sm font-bold tabular-nums {{ $i === 0 ? 'bg-[#fbbf24] text-[#172a37]' : ($i === 1 ? 'bg-[#dcd2bd] text-[#172a37]' : 'bg-[#f97316] text-white') }}">
                                        {{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}
                                    </span>
                                    <span class="font-mono text-[10px] font-semibold uppercase tracking-[0.18em] text-white/70">{{ $podiumLabels[$i] }}</span>
                                </div>

                                <h3 class="relative mt-2 line-clamp-2 font-display text-base font-bold leading-tight text-white lg:text-lg">{{ $anime['title'] }}</h3>

                                <div class="relative mt-2 flex flex-wrap items-center gap-1.5 font-mono text-[10px] font-semibold text-white/80">
                                    <span class="rounded-full bg-white/10 px-2 py-0.5">{{ $anime['format'] }}</span>
                                    @if($anime['year'])
                                        <span class="rounded-full bg-white/10 px-2 py-0.5">{{ $anime['year'] }}</span>
                                    @endif
                                    @if($anime['episodes'])
                                        <span class="rounded-full bg-white/10 px-2 py-0.5">{{ $anime['episodes'] }} EP</span>
                                    @endif
                                    @if($anime['score'])
                                        <span class="ml-auto inline-flex items-center gap-1 text-xs font-bold text-white">
                                            <span class="material-symbols-rounded text-[13px] text-[#fbbf24]">star</span>{{ $anime['score'] }}
                                        </span>
                                    @endif
                                </div>
                            </a>
                        @endforeach
                    </div>

                    {{-- The rest of the chart, as a swipeable poster shelf. --}}
                    @if(count($rest))
                        <div class="mt-3 rounded-2xl border border-[#e0d9c8] bg-[#faf7f0] p-3 sm:p-4">
                            <p class="mb-3 text-[11px] font-bold uppercase tracking-[0.14em] text-[#84948a]">Ranks 4 to {{ count($top10) }}</p>
                            <div class="rail-x no-scrollbar">
                                @foreach($rest as $i => $anime)
                                    <a href="{{ route('anime.show', ['id' => $anime['id']]) }}" class="group w-[96px] sm:w-[112px] lg:w-auto lg:flex-1">
                                        <div class="relative aspect-[2/3] overflow-hidden rounded-xl border border-[#dcd2bd] bg-[#eaddc2] shadow-sm transition duration-300 group-hover:-translate-y-0.5 group-hover:border-[#0f766e]/60 group-hover:shadow-md">
                                            <img src="{{ $anime['coverUrl'] }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                                                 class="h-full w-full object-cover transition duration-500 group-hover:scale-105" />
                                            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-[#0b1a23]/85 via-[#0b1a23]/10 to-transparent"></div>
                                            <span class="absolute bottom-1 left-1.5 font-display text-2xl font-extrabold leading-none text-white drop-shadow-[0_2px_6px_rgba(0,0,0,0.45)]">{{ $i + 4 }}</span>
                                            @if($anime['score'])
                                                <span class="absolute right-1.5 top-1.5 inline-flex items-center gap-0.5 rounded-full bg-black/70 px-1.5 py-0.5 font-mono text-[9px] font-bold text-white">
                                                    <span class="material-symbols-rounded text-[10px] text-[#fbbf24]">star</span>{{ $anime['score'] }}
                                                </span>
                                            @endif
                                        </div>
                                        <h4 class="mt-1.5 line-clamp-2 min-h-[2.15rem] text-[11px] font-semibold leading-snug text-[#172a37] transition-colors group-hover:text-[#0f766e]" title="{{ $anime['title'] }}">{{ $anime['title'] }}</h4>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </section>
            @endif

            {{-- ===== THIS SEASON: POSTER GRID ===== --}}
            @if(!empty($seasonPopular))
                <section>
                    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-[#84948a]">Now airing</p>
                            <h2 class="font-display text-xl font-bold tracking-tight text-[#172a37] sm:text-2xl">Popular this season</h2>
                            <p class="mt-1 text-xs text-[#6f7e75]">{{ ucfirst(strtolower($seasonInfo['season'])) }} {{ $seasonInfo['year'] }} &middot; {{ count($seasonPopular) }} titles to start now</p>
                        </div>
                        <x-section-link :href="route('anime.index', ['category' => 'season'])">View all</x-section-link>
                    </div>
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        @foreach($seasonPopular as $anime)
                            @include('anime.partials.card-poster', ['anime' => $anime])
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- ===== HIGHEST RATED: FEATURED + RANKED LIST ===== --}}
            @php
                $topPick = $topRated[0] ?? null;
                $topRest = array_slice($topRated ?? [], 1, 5);
            @endphp
            @if($topPick)
                <section>
                    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-[#84948a]">All-time favourites</p>
                            <h2 class="font-display text-xl font-bold tracking-tight text-[#172a37] sm:text-2xl">Highest rated</h2>
                        </div>
                        <x-section-link :href="route('anime.index', ['category' => 'top'])">View all</x-section-link>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]">
                        <a href="{{ route('anime.show', ['id' => $topPick['id']]) }}"
                           class="group relative flex min-h-[300px] flex-col justify-end overflow-hidden rounded-3xl border border-[#e0d9c8] bg-[#172a37] p-5 shadow-sm sm:p-7">
                            <img src="{{ $topPick['bannerUrl'] ?: $topPick['coverUrl'] }}" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                                 class="absolute inset-0 h-full w-full object-cover opacity-60 transition duration-500 group-hover:scale-105" />
                            <div class="absolute inset-0 bg-gradient-to-t from-[#172a37] via-[#172a37]/55 to-transparent"></div>
                            <div class="relative space-y-2.5">
                                <span class="inline-flex w-fit items-center gap-1.5 rounded-full bg-[#fbbf24] px-3 py-1 text-[10px] font-bold uppercase tracking-[0.18em] text-[#172a37]">
                                    <span class="material-symbols-rounded text-sm">workspace_premium</span>
                                    #1 highest rated
                                </span>
                                <h3 class="font-display text-2xl font-extrabold leading-tight text-white sm:text-3xl">{{ $topPick['title'] }}</h3>
                                <div class="flex flex-wrap items-center gap-2 text-[11px] font-semibold text-white/85">
                                    <span class="rounded-full bg-white/10 px-2.5 py-1">{{ $topPick['format'] }}</span>
                                    @if($topPick['year'])
                                        <span class="rounded-full bg-white/10 px-2.5 py-1">{{ $topPick['year'] }}</span>
                                    @endif
                                    @if($topPick['episodes'])
                                        <span class="rounded-full bg-white/10 px-2.5 py-1">{{ $topPick['episodes'] }} EP</span>
                                    @endif
                                </div>
                                @if($topPick['score'])
                                    <div class="flex items-center gap-3 pt-1">
                                        <span class="inline-flex items-center gap-1 font-mono text-2xl font-bold text-white">
                                            <span class="material-symbols-rounded text-base text-[#fbbf24]">star</span>{{ $topPick['score'] }}
                                        </span>
                                        <span class="h-1.5 w-32 overflow-hidden rounded-full bg-white/20">
                                            <span class="block h-full rounded-full bg-[#fbbf24]" style="width: {{ (int) round($topPick['score'] * 10) }}%"></span>
                                        </span>
                                    </div>
                                @endif
                                <span class="inline-flex items-center gap-1.5 pt-1 text-xs font-bold text-white/90 transition-colors group-hover:text-[#fbbf24]">
                                    <span class="material-symbols-rounded text-base">play_circle</span> Watch now
                                </span>
                            </div>
                        </a>

                        <div class="overflow-hidden rounded-3xl border border-[#e0d9c8] bg-white shadow-sm">
                            @foreach($topRest as $i => $anime)
                                <a href="{{ route('anime.show', ['id' => $anime['id']]) }}"
                                   class="group flex items-center gap-3 px-4 py-3 transition hover:bg-[#faf6ee] sm:gap-4 sm:px-5 {{ $i > 0 ? 'border-t border-[#efe7d6]' : '' }}">
                                    <div class="w-6 flex-shrink-0 text-right font-mono text-sm font-bold tabular-nums text-[#c1cbbb]">{{ $i + 2 }}</div>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="truncate text-sm font-bold text-[#172a37] transition-colors group-hover:text-[#0f766e]">{{ $anime['title'] }}</h3>
                                        <p class="mt-0.5 truncate font-mono text-[10px] text-[#84948a]">
                                            {{ $anime['format'] }}@if($anime['year']) &middot; {{ $anime['year'] }}@endif
                                        </p>
                                    </div>
                                    @if($anime['score'])
                                        <div class="flex flex-shrink-0 items-center gap-2">
                                            <span class="hidden h-1.5 w-10 overflow-hidden rounded-full bg-[#eaddc2] sm:block">
                                                <span class="block h-full rounded-full bg-[#0f766e]" style="width: {{ (int) round($anime['score'] * 10) }}%"></span>
                                            </span>
                                            <span class="font-mono text-[11px] font-bold text-[#172a37]">{{ $anime['score'] }}</span>
                                        </div>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endif

            {{-- ===== COMING SOON RAIL ===== --}}
            @if(!empty($upcoming))
                <section>
                    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-[#84948a]">Premieres</p>
                            <h2 class="font-display text-xl font-bold tracking-tight text-[#172a37] sm:text-2xl">Coming soon</h2>
                        </div>
                        <x-section-link :href="route('anime.index', ['category' => 'upcoming'])">View all</x-section-link>
                    </div>
                    <div class="rail-x no-scrollbar">
                        @foreach($upcoming as $anime)
                            <div class="w-[280px] flex-shrink-0 sm:w-[320px]">
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
                    <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-[#172a37]" style="font-family: 'Plus Jakarta Sans', sans-serif;">
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
                    {{-- Category chips --}}
                    <div class="flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Kategori anime">
                        @foreach($catLabels as $key => $label)
                            <a href="{{ route('anime.index', array_merge(request()->except(['category', 'page']), ['category' => $key])) }}"
                               role="tab"
                               @if($currentCategory === $key && $search === '') aria-selected="true" @endif
                               class="inline-flex h-8 items-center rounded-full px-3.5 text-xs font-bold transition {{ $currentCategory === $key && $search === '' ? 'bg-[#0f766e] text-white shadow-sm' : 'border border-[#dcd2bd] bg-white/70 text-[#4c5b54] hover:border-[#0f766e]/40 hover:text-[#0f766e]' }}">
                                {{ $label }}
                            </a>
                        @endforeach
                    </div>
                    {{-- Genre: single dropdown button --}}
                    <div class="relative" x-data="{ open: false }" @keydown.escape="open = false">
                        <button type="button" @click="open = !open" class="inline-flex items-center gap-2 rounded-full border border-[#dcd2bd] bg-white px-4 py-2 text-xs font-bold text-[#172a37] shadow-sm transition hover:border-[#0f766e]/50">
                            <span class="material-symbols-rounded text-base text-[#0f766e]">theater_comedy</span>
                            Genre: <span class="text-[#0f766e]">{{ $currentGenre ?: 'All Genres' }}</span>
                            <span class="material-symbols-rounded text-base transition-transform duration-200" :class="open ? 'rotate-180' : ''">expand_more</span>
                        </button>
                        <div x-show="open" x-cloak @click.outside="open = false" class="absolute left-0 z-30 mt-2 w-56 max-w-[calc(100vw-2rem)] overflow-hidden rounded-2xl border border-[#e0d9c8] bg-white p-1.5 shadow-[0_20px_45px_-18px_rgba(23,42,55,0.45)]">
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
                    <div class="rounded-3xl border border-[#dcd2bd] bg-white/70 py-16 text-center">
                        <span class="material-symbols-rounded text-4xl text-[#c1cbbb]">search_off</span>
                        <p class="mt-2 text-sm font-medium text-[#6f7e75]">No matching anime found. Try another keyword or filter.</p>
                    </div>
                @else
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                        @foreach($animes as $anime)
                            @include('anime.partials.card-poster', ['anime' => $anime])
                        @endforeach
                    </div>

                    @include('partials.pagination', ['page' => $page, 'totalPages' => $totalPages, 'routeName' => 'anime.index'])
                @endif
            </section>
        @endif
    </div>
</x-manga-layout>
