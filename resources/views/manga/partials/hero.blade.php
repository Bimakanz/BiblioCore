{{--
    Manga home hero.

    Mirrors the anime home banner: wide full-bleed art, dark gradient, Trending
    pill, title, meta pills, description, and two CTAs. Manga cards carry the
    AniList `bannerImage` (wide 16:9-ish), so this uses that when present and
    falls back to an ambient wash of the cover when a title has no banner.
--}}
@php
    // Slide the top titles, skipping repeats (the catalog keeps MANGA and
    // NOVEL editions of the same name as separate entries).
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
            if (count($heroSlides) === 5) {
                break;
            }
        }
    }
@endphp

@if(count($heroSlides))
    <section x-data="{ active: 0, total: {{ count($heroSlides) }}, timer: null, next() { this.active = (this.active + 1) % this.total }, start() { if (!this.timer && this.total > 1) this.timer = setInterval(() => this.next(), 6000) }, stop() { if (this.timer) { clearInterval(this.timer); this.timer = null } } }"
             x-init="start()" @mouseenter="stop()" @mouseleave="start()"
             class="relative h-[300px] overflow-hidden rounded-2xl bg-[#172a37] shadow-[0_25px_60px_-30px_rgba(23,42,55,0.7)] sm:h-[340px] sm:rounded-3xl lg:h-[360px]">
        @foreach($heroSlides as $i => $slide)
            @php
                $heroArt = $slide['bannerUrl'] ?? null;
            @endphp
            <div class="absolute inset-0 transition-opacity duration-700" :class="active === {{ $i }} ? 'opacity-100 z-10' : 'opacity-0 z-0 pointer-events-none'">
                @if($heroArt)
                    <img src="{{ $heroArt }}" alt="{{ $slide['title'] }} banner"
                         loading="{{ $i === 0 ? 'eager' : 'lazy' }}" referrerpolicy="no-referrer" decoding="async"
                         class="absolute inset-0 h-full w-full object-cover opacity-70" />
                @elseif(!empty($slide['coverUrl']))
                    <img src="{{ $slide['coverUrl'] }}" alt="" aria-hidden="true" referrerpolicy="no-referrer"
                         class="absolute inset-0 h-full w-full scale-110 object-cover opacity-30 blur-2xl" />
                @endif
                <div class="absolute inset-0 bg-gradient-to-r from-[#172a37] via-[#172a37]/75 to-[#172a37]/15"></div>
                <div class="absolute inset-0 bg-gradient-to-t from-[#172a37]/90 via-transparent to-transparent"></div>

                <div class="relative flex h-full flex-col justify-end gap-2.5 p-5 pb-7 sm:gap-3 sm:p-9 sm:pb-10 lg:max-w-[62%]">
                    <div class="inline-flex w-fit items-center gap-2 rounded-full bg-[#f97316] px-3.5 py-1 text-[11px] font-bold uppercase tracking-[0.2em] text-white">
                        <span class="material-symbols-rounded text-sm">local_fire_department</span>
                        Trending #{{ $i + 1 }}
                    </div>
                    <h1 class="max-w-2xl font-display text-2xl font-extrabold leading-tight tracking-tight text-white sm:text-4xl">
                        {{ $slide['title'] }}
                    </h1>
                    <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-white/85">
                        @if(!empty($slide['year']))
                            <span class="rounded-full bg-white/10 px-2.5 py-1">{{ $slide['year'] }}</span>
                        @endif
                        <span class="rounded-full bg-white/10 px-2.5 py-1">{{ $slide['format'] ?? 'MANGA' }}</span>
                        @if(!empty($slide['contentRating']))
                            <span class="rounded-full bg-white/10 px-2.5 py-1">{{ $slide['contentRating'] }}</span>
                        @endif
                        <span class="rounded-full bg-[#0f766e] px-2.5 py-1 text-white">{{ $slide['status'] ?? 'Ongoing' }}</span>
                    </div>
                    <p class="max-w-2xl text-sm leading-relaxed text-white/75 line-clamp-2">
                        {{ \Illuminate\Support\Str::limit(strip_tags($slide['description'] ?? ''), 240) }}
                    </p>
                    <div class="flex flex-wrap gap-3 pt-1">
                        <a href="{{ route('manga.show', $slide['id']) }}"
                           class="inline-flex items-center gap-2 rounded-full bg-white px-5 py-2.5 text-sm font-bold text-[#172a37] transition hover:bg-[#f97316] hover:text-white">
                            <span class="material-symbols-rounded">menu_book</span> Read Now
                        </a>
                        <a href="{{ route('manga.index', ['category' => 'trending']) }}"
                           class="inline-flex items-center gap-2 rounded-full border border-white/40 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-white/10">
                            All Trending
                        </a>
                    </div>
                </div>
            </div>
        @endforeach
    </section>
@endif
