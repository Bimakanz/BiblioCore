{{--
    Manga home hero.
    Full-bleed edge-to-edge layout matching anime home.
--}}
@php
    $heroSlides = [];
    if ($search === '') {
        $seenHero = [];
        foreach ($mangas as $item) {
            $key = strtolower(preg_replace('/[^a-z0-9]/', '', $item['title'] ?? ''));
            if ($key === '' || isset($seenHero[$key])) {
                continue;
            }
            $seenHero[$key] = true;
            $heroSlides[] = $item;
            if (count($heroSlides) === 6) {
                break;
            }
        }
    }
@endphp

@if(count($heroSlides))
    <section x-data="{
            active: 0,
            total: {{ count($heroSlides) }},
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

        @foreach($heroSlides as $i => $slide)
            @php
                $heroArt = $slide['bannerUrl'] ?? null;
            @endphp
            <div class="absolute inset-0 transition-opacity duration-700 ease-in-out"
                 :class="active === {{ $i }} ? 'opacity-100 z-10' : 'opacity-0 z-0 pointer-events-none'">
                
                {{-- 1. Backdrop image & overlays with bottom mask fade (fades to transparent at bottom 30%) --}}
                <div class="absolute inset-0 hero-backdrop-mask">
                    @if($heroArt)
                        <img src="{{ $heroArt }}" alt="{{ $slide['title'] }} banner"
                             @if($i > 0) loading="lazy" @else fetchpriority="high" @endif
                             decoding="async" referrerpolicy="no-referrer"
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
                            @if(!empty($slide['year']))
                                <span class="rounded-full bg-white/20 px-2.5 py-0.5 backdrop-blur-md">{{ $slide['year'] }}</span>
                            @endif
                            <span class="rounded-full bg-white/20 px-2.5 py-0.5 backdrop-blur-md">{{ $slide['format'] ?? 'MANGA' }}</span>
                            @if(!empty($slide['contentRating']))
                                <span class="rounded-full bg-white/20 px-2.5 py-0.5 backdrop-blur-md">{{ $slide['contentRating'] }}</span>
                            @endif
                            <span class="rounded-full bg-[#0f766e] px-2.5 py-0.5 text-white shadow-sm">{{ $slide['status'] ?? 'Ongoing' }}</span>
                        </div>

                        <p class="text-xs leading-relaxed text-white/80 line-clamp-2 drop-shadow-sm sm:text-sm">
                            {{ \Illuminate\Support\Str::limit(strip_tags($slide['description'] ?? ''), 240) }}
                        </p>

                        <div class="flex flex-wrap items-center gap-2.5 pt-1 sm:gap-3">
                            <a href="{{ route('manga.show', $slide['id']) }}"
                               class="inline-flex items-center gap-1.5 rounded-full bg-white px-4 py-2 text-xs font-bold text-[#172a37] shadow-lg transition hover:bg-[#f97316] hover:text-white sm:gap-2 sm:px-5 sm:py-2.5 sm:text-sm">
                                <span class="material-symbols-rounded text-[18px] sm:text-xl">menu_book</span> Read Now
                            </a>
                            <a href="{{ route('manga.index', ['category' => 'trending']) }}"
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
            @foreach($heroSlides as $i => $slide)
                <button type="button" @click="goTo({{ $i }})"
                        :aria-label="'Go to slide {{ $i + 1 }}'"
                        class="h-1.5 rounded-full transition-all duration-300"
                        :class="active === {{ $i }} ? 'w-6 bg-white' : 'w-1.5 bg-white/40 hover:bg-white/70'"></button>
            @endforeach
        </div>
    </section>
@endif
