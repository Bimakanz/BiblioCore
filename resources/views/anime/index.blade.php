<x-manga-layout title="BiblioAnime — Watch Anime with English Subs for Free">
    @if($mode === 'home')
        {{-- ===== HERO SLIDER: Full-bleed iQIYI-inspired layout ===== --}}
        @if(!empty($hero))
            <section x-data="{
                    active: 0,
                    total: {{ count($hero) }},
                    timer: null,
                    visHandler: null,
                    reducedQuery: null,
                    reducedHandler: null,
                    isReducedMotion() {
                        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
                    },
                    next() {
                        this.active = (this.active + 1) % this.total;
                    },
                    goTo(i) {
                        this.active = i;
                        this.stop();
                        this.start();
                    },
                    start() {
                        if (this.isReducedMotion() || document.hidden || this.total <= 1) return;
                        if (!this.timer) {
                            this.timer = setInterval(() => this.next(), 6000);
                        }
                    },
                    stop() {
                        if (this.timer) {
                            clearInterval(this.timer);
                            this.timer = null;
                        }
                    },
                    init() {
                        this.visHandler = () => {
                            if (document.hidden) {
                                this.stop();
                            } else {
                                this.start();
                            }
                        };
                        document.addEventListener('visibilitychange', this.visHandler);

                        if (window.matchMedia) {
                            this.reducedQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
                            this.reducedHandler = (e) => {
                                if (e.matches) {
                                    this.stop();
                                } else {
                                    this.start();
                                }
                            };
                            if (this.reducedQuery.addEventListener) {
                                this.reducedQuery.addEventListener('change', this.reducedHandler);
                            } else if (this.reducedQuery.addListener) {
                                this.reducedQuery.addListener(this.reducedHandler);
                            }
                        }

                        if (typeof this.$cleanup === 'function') {
                            this.$cleanup(() => this.destroy());
                        }

                        this.start();
                    },
                    destroy() {
                        this.stop();
                        if (this.visHandler) {
                            document.removeEventListener('visibilitychange', this.visHandler);
                            this.visHandler = null;
                        }
                        if (this.reducedQuery && this.reducedHandler) {
                            if (this.reducedQuery.removeEventListener) {
                                this.reducedQuery.removeEventListener('change', this.reducedHandler);
                            } else if (this.reducedQuery.removeListener) {
                                this.reducedQuery.removeListener(this.reducedHandler);
                            }
                            this.reducedHandler = null;
                        }
                    }
                }"
                @mouseenter="stop()"
                @mouseleave="start()"
                @touchstart.passive="stop()"
                @touchend.passive="start()"
                @touchcancel.passive="start()"
                class="relative w-full h-[520px] sm:h-[560px] lg:h-[600px] overflow-hidden bg-transparent">

                @foreach($hero as $i => $slide)
                    <div class="absolute inset-0 transition-opacity duration-700 ease-in-out"
                         :class="active === {{ $i }} ? 'opacity-100 z-10' : 'opacity-0 z-0 pointer-events-none'">
                        {{-- 1. Backdrop image & overlays with bottom mask fade (fades to transparent at bottom 30%) --}}
                        <div class="absolute inset-0 hero-backdrop-mask">
                            @if($slide['heroUrl'] || $slide['bannerUrl'])
                                <img src="{{ $slide['heroUrl'] ?: $slide['bannerUrl'] }}"
                                     alt="{{ $slide['title'] }}"
                                     @if($i > 0) loading="lazy" @else fetchpriority="high" @endif
                                     decoding="async"
                                     referrerpolicy="no-referrer"
                                     class="absolute inset-0 h-full w-full object-cover object-center" />
                            @else
                                <div class="absolute inset-0 bg-gradient-to-br from-[#172a37] via-[#111e28] to-[#0e1419]"></div>
                            @endif

                            {{-- Top dark gradient for transparent navbar legibility --}}
                            <div class="absolute inset-x-0 top-0 h-32 bg-gradient-to-b from-black/80 via-black/40 to-transparent z-10"></div>

                            {{-- Left dark gradient for text block legibility --}}
                            <div class="absolute inset-0 bg-gradient-to-r from-[#0b141a]/95 via-[#0b141a]/65 via-45% to-transparent z-10"></div>
                        </div>

                        {{-- 2. Bottom fade for light mode --}}
                        <div class="pointer-events-none absolute inset-x-0 bottom-0 h-28 sm:h-32 hero-bottom-fade z-20"></div>

                        {{-- 3. Text content block on the left (bottom-aligned, z-index paling atas) --}}
                        <div class="relative mx-auto flex h-full w-full max-w-[1440px] flex-col justify-end px-4 sm:px-6 lg:px-7 pt-20 pb-14 sm:pb-16 lg:pb-16 z-30">
                            <div class="max-w-2xl space-y-3 sm:space-y-4">
                                <div class="inline-flex w-fit items-center gap-1.5 rounded-full bg-[#f97316] px-3 py-1 text-[10px] font-bold uppercase tracking-[0.16em] text-white sm:px-3.5 sm:text-[11px] shadow-sm">
                                    <span class="material-symbols-rounded text-sm">local_fire_department</span>
                                    Trending #{{ $i + 1 }}
                                </div>

                                <h1 class="line-clamp-2 text-2xl font-extrabold leading-tight tracking-tight text-white drop-shadow-md sm:text-4xl xl:text-5xl">
                                    {{ $slide['title'] }}
                                </h1>

                                <div class="flex flex-wrap items-center gap-1.5 text-[11px] font-semibold text-white/90 sm:gap-2 sm:text-xs">
                                    @if($slide['score'])
                                        <span class="inline-flex items-center gap-1 rounded-full bg-white/20 px-2.5 py-0.5 backdrop-blur-md">
                                            <span class="material-symbols-rounded text-sm text-[#fbbf24]">star</span>{{ $slide['score'] }}
                                        </span>
                                    @endif
                                    @if($slide['year'])
                                        <span class="rounded-full bg-white/20 px-2.5 py-0.5 backdrop-blur-md">{{ $slide['year'] }}</span>
                                    @endif
                                    <span class="rounded-full bg-white/20 px-2.5 py-0.5 backdrop-blur-md">{{ $slide['format'] }}</span>
                                    @if($slide['episodes'])
                                        <span class="rounded-full bg-white/20 px-2.5 py-0.5 backdrop-blur-md">{{ $slide['episodes'] }} EP</span>
                                    @endif
                                    <span class="rounded-full bg-[#0f766e] px-2.5 py-0.5 text-white shadow-sm">{{ $slide['status'] }}</span>
                                </div>

                                <p class="text-xs leading-relaxed text-white/80 line-clamp-2 drop-shadow-sm sm:text-sm">
                                    {{ $slide['description'] }}
                                </p>

                                <div class="flex flex-wrap items-center gap-2.5 pt-1 sm:gap-3">
                                    <a href="{{ route('anime.show', ['id' => $slide['id']]) }}"
                                       class="inline-flex items-center gap-1.5 rounded-full bg-white px-4 py-2 text-xs font-bold text-[#172a37] shadow-lg transition hover:bg-[#f97316] hover:text-white sm:gap-2 sm:px-5 sm:py-2.5 sm:text-sm">
                                        <span class="material-symbols-rounded text-[18px] sm:text-xl">play_circle</span> Watch Now
                                    </a>
                                    <a href="{{ route('anime.index', ['category' => 'trending']) }}"
                                       class="inline-flex items-center gap-1.5 rounded-full border border-white/40 bg-black/20 px-4 py-2 text-xs font-bold text-white backdrop-blur-sm transition hover:bg-white/20 sm:gap-2 sm:px-5 sm:py-2.5 sm:text-sm">
                                        All Trending
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach

                {{-- Dot Indicators at bottom-right, aligned with buttons, inside hero, above fade --}}
                <div class="absolute right-6 lg:right-12 bottom-[60px] sm:bottom-[70px] lg:bottom-[72px] z-40 flex items-center gap-1.5">
                    @foreach($hero as $i => $slide)
                        <button type="button" @click="goTo({{ $i }})"
                                :aria-label="'Go to slide {{ $i + 1 }}'"
                                class="h-1.5 rounded-full transition-all duration-300"
                                :class="active === {{ $i }} ? 'w-6 bg-white' : 'w-1.5 bg-white/40 hover:bg-white/70'"></button>
                    @endforeach
                </div>
            </section>
        @endif

        {{-- ===== MAIN HOME CONTENT ===== --}}
        <div class="mx-auto w-full max-w-[1440px] px-4 sm:px-6 lg:px-7 space-y-9 pt-3 sm:pt-4 relative z-20">

            {{-- ===== CONTINUE WATCHING (anime history, matching manga continue reading) ===== --}}
            <section x-data="{
                        items: JSON.parse(localStorage.getItem('biblioanime_history') || '[]'),
                        rel(item) {
                            const raw = item.updatedAt || item.watchedAt;
                            if (raw) {
                                const d = Number(raw) || (raw ? Date.parse(raw) : 0);
                                if (d && d > 1577836800000) {
                                    const diff = Math.max(0, Date.now() - d);
                                    const m = Math.floor(diff / 60000);
                                    if (m < 1) return 'Watched just now';
                                    if (m < 60) return 'Watched ' + m + 'm ago';
                                    const h = Math.floor(m / 60);
                                    if (h < 24) return 'Watched ' + h + 'h ago';
                                    return 'Watched ' + Math.floor(h / 24) + 'd ago';
                                }
                            }
                            return 'Watched recently';
                        }
                     }"
                     x-show="items.length > 0" x-cloak>
                <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-[#84948a]">Pick up where you left off</p>
                        <h2 class="font-display text-xl font-bold text-[#172a37] sm:text-2xl">Continue Watching</h2>
                    </div>
                    <x-section-link @click="openHistory()" class="!bg-white !border-[#e0d9c8] !text-[#0f766e] dark:!bg-[#172a37] dark:!border-[#223440] dark:!text-[#2dd4bf] relative z-30 shadow-sm">View all history</x-section-link>
                </div>

                <div class="rail-x no-scrollbar">
                    <template x-for="item in items.slice(0, 10)" :key="item.id + '-' + (item.episodeId || '')">
                        <a :href="'/anime/' + item.id + '/watch?ep=' + encodeURIComponent(item.episodeId || '') + '&n=' + encodeURIComponent(item.episodeNumber || 1) + '&t=' + Math.max(0, Math.floor((item.positionSec || 0) - 5))"
                           class="group flex w-[280px] items-center gap-3 rounded-2xl border border-[#e0d9c8] bg-white/80 p-2.5 shadow-sm transition hover:border-[#0f766e]/50 hover:shadow-md sm:w-[320px]">
                            <img :src="item.coverUrl || item.bannerUrl || 'https://via.placeholder.com/60x84?text=No+Cover'" alt="" loading="lazy" decoding="async"
                                 class="h-[84px] w-[58px] flex-shrink-0 rounded-lg object-cover" />
                            <div class="min-w-0 flex-1">
                                <h3 class="truncate text-sm font-bold text-[#172a37]" x-text="item.title"></h3>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] text-[#7b8a82]">
                                    <span class="font-bold text-[#0f766e]">Ep <span x-text="item.episodeNumber || '1'"></span></span>
                                    <span>&middot;</span>
                                    <span x-text="rel(item)"></span>
                                </div>
                            </div>
                            <span class="grid h-9 w-9 flex-shrink-0 place-items-center rounded-full bg-[#0f766e] text-white transition group-hover:bg-[#115e59]">
                                <span class="material-symbols-rounded text-[18px]">play_arrow</span>
                            </span>
                        </a>
                    </template>
                </div>
            </section>

            @php
                $nowAiring = array_slice($seasonPopular ?? [], 0, 6);
                $popularThisSeason = count($seasonPopular ?? []) > 6 ? array_slice($seasonPopular, 6, 6) : ($seasonPopular ?? []);
            @endphp

            {{-- 1. NOW AIRING --}}
            @if(!empty($nowAiring))
                <section>
                    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-[#84948a]">Now airing</p>
                            <h2 class="font-display text-xl font-bold tracking-tight text-[#172a37] sm:text-2xl">Now Airing</h2>
                            <p class="mt-1 text-xs text-[#6f7e75]">{{ ucfirst(strtolower($seasonInfo['season'])) }} {{ $seasonInfo['year'] }} &middot; Currently broadcasting episodes</p>
                        </div>
                        <x-section-link :href="route('anime.index', ['category' => 'season'])">View all</x-section-link>
                    </div>
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                        @foreach($nowAiring as $anime)
                            @include('anime.partials.card-poster', ['anime' => $anime])
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- 2. POPULAR THIS SEASON --}}
            @if(!empty($popularThisSeason))
                <section>
                    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="font-mono text-[10px] uppercase tracking-[0.18em] text-[#84948a]">Season highlights</p>
                            <h2 class="font-display text-xl font-bold tracking-tight text-[#172a37] sm:text-2xl">Popular This Season</h2>
                            <p class="mt-1 text-xs text-[#6f7e75]">{{ ucfirst(strtolower($seasonInfo['season'])) }} {{ $seasonInfo['year'] }} &middot; Fan-favourite titles to watch</p>
                        </div>
                        <x-section-link :href="route('anime.index', ['category' => 'season'])">View all</x-section-link>
                    </div>
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                        @foreach($popularThisSeason as $anime)
                            @include('anime.partials.card-poster', ['anime' => $anime])
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- 3. TRENDING CHART / TOP 10 RIGHT NOW --}}
            @php
                $top10 = array_slice($trending ?? [], 0, 10);
                $podium = array_slice($top10, 0, 3);
                $rest = array_slice($top10, 3);
                $rankBadgeStyles = [
                    'bg-[#fbbf24] text-[#172a37]',
                    'bg-[#e2e8f0] text-[#172a37]',
                    'bg-[#f97316] text-white',
                ];
            @endphp
            @if(count($top10))
                <section>
                    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-[#84948a]">Trending chart</p>
                            <h2 class="font-display text-xl font-bold tracking-tight text-[#172a37] sm:text-2xl">Top {{ count($top10) }} right now</h2>
                        </div>
                        <x-section-link :href="route('anime.index', ['category' => 'trending'])">Full ranking</x-section-link>
                    </div>

                    {{-- Top 3: balanced, clean 3-column showcase --}}
                    <div class="grid gap-3.5 sm:grid-cols-3">
                        @foreach($podium as $i => $anime)
                            <a href="{{ route('anime.show', ['id' => $anime['id']]) }}"
                               class="group relative flex min-h-[210px] flex-col justify-between overflow-hidden rounded-2xl border border-[#e0d9c8] bg-[#172a37] p-4 shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-[0_20px_42px_-18px_rgba(23,42,55,0.55)] sm:min-h-[248px] sm:rounded-3xl sm:p-5">
                                <img src="{{ $anime['bannerUrl'] ?: $anime['coverUrl'] }}" alt="{{ $anime['title'] }}" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                                     class="absolute inset-0 h-full w-full object-cover object-top opacity-85 transition duration-700 group-hover:scale-105" />
                                <div class="absolute inset-0 bg-gradient-to-t from-[#0b1a23]/95 via-[#0b1a23]/40 to-[#0b1a23]/15"></div>

                                <div class="relative flex items-center justify-between gap-2">
                                    <span class="inline-flex h-7 items-center justify-center rounded-full px-2.5 text-xs font-extrabold tabular-nums shadow-sm {{ $rankBadgeStyles[$i] ?? 'bg-white text-[#172a37]' }}">
                                        #{{ $i + 1 }}
                                    </span>
                                    @if($anime['score'])
                                        <span class="inline-flex items-center gap-1 rounded-full bg-black/65 px-2.5 py-1 text-xs font-bold text-white tabular-nums">
                                            <span class="material-symbols-rounded text-[13px] text-[#fbbf24]">star</span>{{ $anime['score'] }}
                                        </span>
                                    @endif
                                </div>

                                <div class="relative mt-auto pt-12">
                                    <h3 class="line-clamp-2 font-display text-base font-extrabold leading-snug text-white transition-colors group-hover:text-[#fde68a] lg:text-lg">{{ $anime['title'] }}</h3>
                                    <div class="mt-2 flex flex-wrap items-center gap-1.5 text-[11px] font-semibold text-white/85">
                                        <span class="rounded-md bg-white/15 px-2 py-0.5">{{ $anime['format'] }}</span>
                                        @if($anime['year'])
                                            <span class="rounded-md bg-white/15 px-2 py-0.5">{{ $anime['year'] }}</span>
                                        @endif
                                        @if($anime['episodes'])
                                            <span class="rounded-md bg-white/15 px-2 py-0.5">{{ $anime['episodes'] }} EP</span>
                                        @endif
                                    </div>
                                </div>
                            </a>
                        @endforeach
                    </div>

                    {{-- The rest of the chart, as a swipeable poster shelf. --}}
                    @if(count($rest))
                        <div class="mt-4 rounded-2xl border border-[#e0d9c8] bg-white/75 p-3.5 shadow-sm sm:rounded-3xl sm:p-5">
                            <div class="mb-3 flex items-center justify-between">
                                <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-[#84948a]">Ranks 4 to {{ count($top10) }}</p>
                                <span class="text-[11px] font-medium text-[#84948a] lg:hidden">Swipe &rarr;</span>
                            </div>
                            <div class="rail-x no-scrollbar !pb-0">
                                @foreach($rest as $i => $anime)
                                    <a href="{{ route('anime.show', ['id' => $anime['id']]) }}" class="group w-[118px] sm:w-[134px] lg:w-auto lg:flex-1">
                                        <div class="relative aspect-[2/3] overflow-hidden rounded-xl border border-[#dcd2bd] bg-[#eaddc2] shadow-sm transition duration-300 group-hover:-translate-y-1 group-hover:border-[#0f766e]/60 group-hover:shadow-lg">
                                            <img src="{{ $anime['coverUrl'] }}" alt="{{ $anime['title'] }}" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                                                 class="h-full w-full object-cover object-top transition duration-500 group-hover:scale-105" />
                                            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-[#0b1a23]/85 via-[#0b1a23]/10 to-transparent"></div>
                                            <span class="absolute bottom-1 left-2 font-display text-2xl font-extrabold leading-none text-white drop-shadow-[0_2px_6px_rgba(0,0,0,0.55)] tabular-nums">
                                                {{ $i + 4 }}
                                            </span>
                                            @if($anime['score'])
                                                <span class="absolute right-1.5 top-1.5 inline-flex items-center gap-0.5 rounded-full bg-black/70 px-1.5 py-0.5 text-[10px] font-bold text-white tabular-nums">
                                                    <span class="material-symbols-rounded text-[11px] text-[#fbbf24]">star</span>{{ $anime['score'] }}
                                                </span>
                                            @endif
                                        </div>
                                        <h4 class="mt-2 line-clamp-2 min-h-[2.1rem] text-xs font-bold leading-snug text-[#172a37] transition-colors group-hover:text-[#0f766e]" title="{{ $anime['title'] }}">{{ $anime['title'] }}</h4>
                                        @if($anime['year'] || $anime['episodes'])
                                            <div class="mt-0.5 flex items-center gap-1.5 text-[10px] font-medium text-[#7b8a82]">
                                                @if($anime['year'])<span>{{ $anime['year'] }}</span>@endif
                                                @if($anime['episodes'])<span>{{ $anime['episodes'] }} EP</span>@endif
                                            </div>
                                        @endif
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </section>
            @endif

            {{-- 4. HIGHEST RATED: FEATURED + RANKED LIST --}}
            @php
                $topPick = $topRated[0] ?? null;
                $topRest = array_slice($topRated ?? [], 1, 5);
            @endphp
            @if($topPick)
                <section>
                    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-[0.14em] text-[#84948a]">All-time favourites</p>
                            <h2 class="font-display text-xl font-bold tracking-tight text-[#172a37] sm:text-2xl">Highest rated</h2>
                        </div>
                        <x-section-link :href="route('anime.index', ['category' => 'top'])">View all</x-section-link>
                    </div>

                    <div class="grid gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
                        {{-- #1 Spotlight --}}
                        <a href="{{ route('anime.show', ['id' => $topPick['id']]) }}"
                           class="group relative flex min-h-[280px] flex-col justify-between overflow-hidden rounded-2xl border border-[#e0d9c8] bg-[#172a37] p-5 shadow-sm transition duration-300 hover:shadow-[0_22px_48px_-20px_rgba(23,42,55,0.6)] sm:min-h-[340px] sm:rounded-3xl sm:p-7">
                            <img src="{{ $topPick['bannerUrl'] ?: $topPick['coverUrl'] }}" alt="{{ $topPick['title'] }}" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                                 class="absolute inset-0 h-full w-full object-cover object-top opacity-75 transition duration-700 group-hover:scale-105" />
                            <div class="absolute inset-0 bg-gradient-to-t from-[#0b1a23]/95 via-[#0b1a23]/55 to-[#0b1a23]/15"></div>

                            <div class="relative flex items-center justify-between gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-[#fbbf24] px-3 py-1 text-[11px] font-extrabold uppercase tracking-wide text-[#172a37] shadow-sm">
                                    <span class="material-symbols-rounded text-[15px]">workspace_premium</span>
                                    #1 Highest Rated
                                </span>
                                @if($topPick['score'])
                                    <span class="inline-flex items-center gap-1 rounded-full bg-black/65 px-3 py-1 text-sm font-extrabold text-white tabular-nums">
                                        <span class="material-symbols-rounded text-[15px] text-[#fbbf24]">star</span>{{ number_format((float) $topPick['score'], 1) }}
                                    </span>
                                @endif
                            </div>

                            <div class="relative mt-auto space-y-3 pt-16">
                                <h3 class="font-display text-2xl font-extrabold leading-tight text-white transition-colors group-hover:text-[#fde68a] sm:text-3xl">{{ $topPick['title'] }}</h3>
                                <div class="flex flex-wrap items-center gap-1.5 text-[11px] font-semibold text-white/90">
                                    <span class="rounded-md bg-white/15 px-2.5 py-0.5">{{ $topPick['format'] }}</span>
                                    @if($topPick['year'])
                                        <span class="rounded-md bg-white/15 px-2.5 py-0.5">{{ $topPick['year'] }}</span>
                                    @endif
                                    @if($topPick['episodes'])
                                        <span class="rounded-md bg-white/15 px-2.5 py-0.5">{{ $topPick['episodes'] }} EP</span>
                                    @endif
                                </div>
                                @if(!empty($topPick['description']))
                                    <p class="max-w-lg text-xs leading-relaxed text-white/75 line-clamp-2 sm:text-sm">{{ $topPick['description'] }}</p>
                                @endif
                                <div class="pt-1">
                                    <span class="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-xs font-bold text-[#172a37] shadow-sm transition group-hover:bg-[#f97316] group-hover:text-white">
                                        <span class="material-symbols-rounded text-[17px]">play_circle</span>
                                        Watch Now
                                    </span>
                                </div>
                            </div>
                        </a>

                        {{-- Ranks #2 to #6 with poster thumbnails --}}
                        <div class="flex flex-col justify-between overflow-hidden rounded-2xl border border-[#e0d9c8] bg-white p-2 shadow-sm sm:rounded-3xl sm:p-2.5">
                            @foreach($topRest as $i => $anime)
                                <a href="{{ route('anime.show', ['id' => $anime['id']]) }}"
                                   class="group flex items-center gap-3 rounded-xl px-2.5 py-2 transition hover:bg-[#f6f1e8] sm:gap-3.5 sm:px-3">
                                    <span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-lg bg-[#f6f1e8] text-xs font-extrabold text-[#5f6b63] tabular-nums transition group-hover:bg-[#0f766e] group-hover:text-white">
                                        {{ $i + 2 }}
                                    </span>
                                    <img src="{{ $anime['coverUrl'] }}" alt="{{ $anime['title'] }}" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                                         class="h-14 w-10 flex-shrink-0 rounded-lg border border-[#dcd2bd] object-cover object-top shadow-sm" />
                                    <div class="min-w-0 flex-1">
                                        <h3 class="truncate text-sm font-bold text-[#172a37] transition-colors group-hover:text-[#0f766e]">{{ $anime['title'] }}</h3>
                                        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] font-medium text-[#7b8a82]">
                                            <span class="rounded bg-[#f6f1e8] px-1.5 py-0.5 text-[10px] font-semibold text-[#5f6b63]">{{ $anime['format'] }}</span>
                                            @if($anime['year'])<span>{{ $anime['year'] }}</span>@endif
                                            @if($anime['episodes'])<span>{{ $anime['episodes'] }} EP</span>@endif
                                        </div>
                                    </div>
                                    @if($anime['score'])
                                        <span class="inline-flex flex-shrink-0 items-center gap-1 rounded-full bg-[#fef3c7] px-2.5 py-1 text-xs font-bold text-[#92400e] tabular-nums">
                                            <span class="material-symbols-rounded text-[13px] text-[#f59e0b]">star</span>{{ number_format((float) $anime['score'], 1) }}
                                        </span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                </section>
            @endif

            {{-- 6. COMING SOON RAIL --}}
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

        </div>
    @else
        {{-- ===== BROWSE / SEARCH / CATEGORY ===== --}}
        <div class="mx-auto w-full max-w-[1440px] px-4 sm:px-6 lg:px-7 space-y-5">
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
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
                    @foreach($animes as $anime)
                        @include('anime.partials.card-poster', ['anime' => $anime])
                    @endforeach
                </div>

                @include('partials.pagination', ['page' => $page, 'totalPages' => $totalPages, 'routeName' => 'anime.index'])
            @endif
        </div>
    @endif
</x-manga-layout>
