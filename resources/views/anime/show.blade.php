<x-manga-layout title="{{ $anime['title'] }} — BiblioAnime">
    <div class="space-y-4">
        {{-- Back link --}}
        <div class="flex flex-wrap items-center gap-2.5">
            <a href="{{ route('anime.index') }}"
               class="inline-flex items-center gap-2 rounded-full border border-[#dcd2bd] bg-white px-4 py-2 text-sm font-bold text-[#172a37] shadow-sm transition hover:border-[#0f766e] hover:text-[#0f766e]">
                <span class="material-symbols-rounded text-[18px]">arrow_back</span>
                <span>Back to Anime</span>
            </a>
            <span class="material-symbols-rounded text-base text-[#84948a]">chevron_right</span>
            <span class="max-w-[65vw] truncate text-sm font-bold text-[#172a37]">{{ $anime['title'] }}</span>
        </div>

        {{-- Banner --}}
        <div class="relative h-52 sm:h-72 overflow-hidden rounded-2xl sm:rounded-3xl bg-[#172a37]">
            @if($anime['bannerUrl'])
                <img src="{{ $anime['bannerUrl'] }}" alt="" referrerpolicy="no-referrer" decoding="async" class="h-full w-full object-cover object-center" />
            @else
                <img src="{{ $anime['coverUrl'] }}" alt="" referrerpolicy="no-referrer" decoding="async" class="h-full w-full object-cover object-[center_22%] opacity-75" />
            @endif
            <div class="absolute inset-0 bg-gradient-to-t from-[#172a37]/80 via-transparent to-transparent"></div>
        </div>

        <div class="mx-auto w-full max-w-7xl px-4 sm:px-6">
            {{-- Header card --}}
            <div class="relative -mt-24 sm:-mt-32 flex flex-col gap-6 sm:flex-row">
                <img src="{{ $anime['coverUrl'] }}" alt="{{ $anime['title'] }}"
                     class="w-36 sm:w-48 aspect-[3/4] rounded-3xl border-4 border-white object-cover shadow-xl flex-shrink-0 mx-auto sm:mx-0" />
                <div class="flex-1 space-y-3 pt-2 sm:pt-24 text-center sm:text-left">
                    <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2">
                        <span class="rounded-full bg-[#0f766e] px-3 py-0.5 text-[11px] font-bold text-white">{{ $anime['status'] }}</span>
                        <span class="rounded-full bg-[#172a37] px-3 py-0.5 text-[11px] font-bold text-white">{{ $anime['format'] }}</span>
                        @if($anime['season'])<span class="rounded-full bg-white border border-[#dcd2bd] px-3 py-0.5 text-[11px] font-bold text-[#4c5b54]">{{ $anime['season'] }}</span>@endif
                        @if($anime['nextAiring'])
                            <span class="rounded-full bg-[#f97316]/10 border border-[#f97316]/30 px-3 py-0.5 text-[11px] font-bold text-[#ea580c]">
                                Episode {{ $anime['nextAiring']['episode'] }} airs in {{ (int) floor($anime['nextAiring']['timeUntilAiring'] / 86400) }}d {{ (int) floor(($anime['nextAiring']['timeUntilAiring'] % 86400) / 3600) }}h
                            </span>
                        @endif
                    </div>
                    <h1 class="text-2xl sm:text-4xl font-extrabold tracking-tight text-[#172a37]" style="font-family: 'Plus Jakarta Sans', sans-serif;">{{ $anime['title'] }}</h1>
                    @if(!empty($anime['altTitles']))
                        <p class="text-xs text-[#84948a] line-clamp-1">Also known as: {{ implode(', ', array_slice($anime['altTitles'], 0, 3)) }}</p>
                    @endif
                    <div class="flex flex-wrap items-center justify-center sm:justify-start gap-2 text-xs font-semibold text-[#4c5b54]">
                        @if($anime['score'])
                            <span class="inline-flex items-center gap-1 rounded-xl border border-[#dcd2bd] bg-white/85 px-2.5 py-1">
                                <span class="material-symbols-rounded text-[15px] text-[#f59e0b]">star</span>
                                <span class="text-[#84948a]">Score:</span>
                                <strong class="font-bold text-[#172a37]">{{ $anime['score'] }}/10</strong>
                            </span>
                        @endif
                        @if($anime['year'])
                            <span class="inline-flex items-center gap-1 rounded-xl border border-[#dcd2bd] bg-white/85 px-2.5 py-1">
                                <span class="text-[#84948a]">Year:</span>
                                <strong class="font-bold text-[#172a37]">{{ $anime['year'] }}</strong>
                            </span>
                        @endif
                        @if($anime['episodes'])
                            <span class="inline-flex items-center gap-1 rounded-xl border border-[#dcd2bd] bg-white/85 px-2.5 py-1">
                                <span class="text-[#84948a]">Episodes:</span>
                                <strong class="font-bold text-[#172a37]">{{ $anime['episodes'] }}</strong>
                            </span>
                        @endif
                        @if($anime['duration'])
                            <span class="inline-flex items-center gap-1 rounded-xl border border-[#dcd2bd] bg-white/85 px-2.5 py-1">
                                <span class="text-[#84948a]">Duration:</span>
                                <strong class="font-bold text-[#172a37]">{{ $anime['duration'] }} min/ep</strong>
                            </span>
                        @endif
                        @if(!empty($anime['studios']))
                            <span class="inline-flex items-center gap-1 rounded-xl border border-[#dcd2bd] bg-white/85 px-2.5 py-1">
                                <span class="text-[#84948a]">Studio:</span>
                                <strong class="font-bold text-[#172a37]">{{ implode(', ', $anime['studios']) }}</strong>
                            </span>
                        @endif
                    </div>
                    @if(!empty($anime['genres']))
                        <div class="flex flex-wrap justify-center sm:justify-start gap-1.5">
                            @foreach($anime['genres'] as $genre)
                                <a href="{{ route('anime.index', ['genre' => $genre]) }}" class="rounded-full bg-white border border-[#dcd2bd] px-3 py-1 text-[11px] font-semibold text-[#4c5b54] transition hover:border-[#0f766e]/50 hover:text-[#0f766e]">{{ $genre }}</a>
                            @endforeach
                        </div>
                    @endif

                    {{-- Resume (client-side, from biblioanime_history) --}}
                    @if($available)
                    <div x-data="{ entry: (JSON.parse(localStorage.getItem('biblioanime_history') || '[]')).find(h => h.id === '{{ $anime['id'] }}') || null, fmt(s) { s = Math.max(0, Math.floor(s || 0)); const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), ss = String(s % 60).padStart(2, '0'); return h > 0 ? h + ':' + String(m).padStart(2, '0') + ':' + ss : m + ':' + ss; }, rel(ts) { const d = Number(ts) || (ts ? Date.parse(ts) : 0); if (!d) return 'just now'; const diff = Math.max(0, Date.now() - d); const m = Math.floor(diff / 60000); if (m < 1) return 'just now'; if (m < 60) return m + 'm ago'; const h = Math.floor(m / 60); if (h < 24) return h + 'h ago'; return Math.floor(h / 24) + 'd ago'; } }" x-cloak>
                        <template x-if="entry">
                            <a :href="'/anime/{{ $anime['id'] }}/watch?ep=' + encodeURIComponent(entry.episodeId || '') + '&n=' + encodeURIComponent(entry.episodeNumber || 1) + '&t=' + Math.max(0, Math.floor((entry.positionSec || 0) - 5))"
                               class="inline-flex flex-wrap items-center gap-2 rounded-full bg-[#f97316] px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-[#ea580c]">
                                <span class="material-symbols-rounded">resume</span>
                                Resume Episode <span x-text="entry.episodeNumber"></span>
                                <span class="text-[11px] font-semibold text-white/80" x-text="entry.durationSec > 0 ? fmt(entry.positionSec) + ' / ' + fmt(entry.durationSec) : ''"></span>
                                <span class="text-[11px] font-semibold text-white/80" x-text="rel(entry.updatedAt || entry.watchedAt)"></span>
                            </a>
                        </template>
                    </div>
                    @else
                    <div class="w-full max-w-2xl rounded-2xl border border-[#f0d9b5] bg-[#fff8ec] px-5 py-4 text-left">
                        <p class="flex items-center gap-2 text-sm font-bold text-[#b45309]">
                            <span class="material-symbols-rounded text-base">info</span>
                            Not available to watch
                        </p>
                        <p class="mt-1 text-xs leading-relaxed text-[#8a6d3b]">
                            {{ request('unavailable') ? 'No server can play this title right now.' : 'This title has no digital release yet, so there is no video source to play. Titles without video are no longer shown in search or the catalog.' }}
                        </p>
                    </div>
                    @endif
                </div>
            </div>

            <div class="mt-8 grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start">
                <div class="min-w-0 space-y-6">
                    {{-- Synopsis --}}
                    <section class="rounded-2xl border border-[#e0d9c8] bg-white/85 p-5 shadow-sm">
                        <h2 class="font-display text-lg font-bold text-[#172a37]">Synopsis</h2>
                        <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-[#4c5b54]">{{ $anime['description'] }}</p>
                    </section>

                    {{-- Title names --}}
                    @if(!empty($anime['titles']) || !empty($anime['synonyms']))
                        <section class="rounded-2xl border border-[#e0d9c8] bg-white/85 p-5 shadow-sm">
                            <h2 class="font-display text-base font-bold text-[#172a37]">Alternative titles</h2>
                            <dl class="mt-3 space-y-2 text-sm">
                                @if(!empty($anime['titles']['native']))
                                    <div class="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
                                        <dt class="w-24 flex-shrink-0 font-semibold text-[#172a37]">Native</dt>
                                        <dd class="text-[#4c5b54]">{{ $anime['titles']['native'] }}</dd>
                                    </div>
                                @endif
                                @if(!empty($anime['titles']['english']))
                                    <div class="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
                                        <dt class="w-24 flex-shrink-0 font-semibold text-[#172a37]">English</dt>
                                        <dd class="text-[#4c5b54]">{{ $anime['titles']['english'] }}</dd>
                                    </div>
                                @endif
                                @if(!empty($anime['titles']['romaji']))
                                    <div class="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
                                        <dt class="w-24 flex-shrink-0 font-semibold text-[#172a37]">Romaji</dt>
                                        <dd class="text-[#4c5b54]">{{ $anime['titles']['romaji'] }}</dd>
                                    </div>
                                @endif
                                @if(!empty($anime['synonyms']))
                                    <div class="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
                                        <dt class="w-24 flex-shrink-0 font-semibold text-[#172a37]">Synonyms</dt>
                                        <dd class="leading-relaxed text-[#4c5b54]">{{ implode(', ', $anime['synonyms']) }}</dd>
                                    </div>
                                @endif
                            </dl>
                        </section>
                    @endif

                    {{-- Episodes (async) --}}
                    @if($available)
                    <section id="episode-list" class="overflow-hidden rounded-2xl border border-[#e0d9c8] bg-white/85 shadow-sm"
                             x-data="{
                                loading: true,
                                failed: false,
                                episodes: [],
                                query: '',
                                rangeStart: 1,
                                perRange: 100,
                                async load() {
                                    this.loading = true;
                                    this.failed = false;
                                    try {
                                        const res = await fetch('{{ $episodesUrl }}');
                                        const data = await res.json();
                                        this.episodes = data.episodes || [];
                                        // Warm the stream cache in the background so pressing
                                        // play starts almost instantly: the browser caches
                                        // stream.json and the server resolves + verifies the
                                        // source now instead of at click time.
                                        if (this.episodes.length) {
                                            const streamBase = '{{ url('/anime/' . $anime['id'] . '/stream.json') }}';
                                            const warm = (epId) => { fetch(streamBase + '?ep=' + encodeURIComponent(epId)).catch(() => {}); };
                                            warm(this.episodes[0].id);
                                            try {
                                                const hist = JSON.parse(localStorage.getItem('biblioanime_history') || '[]');
                                                const mine = hist.find(h => h.id === '{{ $anime['id'] }}');
                                                if (mine && mine.episodeId && mine.episodeId !== this.episodes[0].id) warm(mine.episodeId);
                                            } catch (e) {}
                                        }
                                    } catch (e) {
                                        this.failed = true;
                                    } finally {
                                        this.loading = false;
                                    }
                                },
                                get ranges() {
                                    const out = [];
                                    for (let s = 1; s <= this.episodes.length; s += this.perRange) out.push(s);
                                    return out;
                                },
                                get visible() {
                                    let list = this.episodes;
                                    if (this.query.trim() !== '') {
                                        const q = this.query.trim();
                                        list = list.filter(ep => String(ep.number).includes(q) || (ep.title || '').toLowerCase().includes(q.toLowerCase()));
                                    } else {
                                        list = list.filter(ep => ep.number >= this.rangeStart && ep.number < this.rangeStart + this.perRange);
                                    }
                                    return list;
                                }
                             }"
                             x-init="load()">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#e0d9c8] p-4">
                            <div>
                                <h2 class="font-display text-lg font-bold text-[#172a37]">Episode</h2>
                                <p class="mt-0.5 font-mono text-[11px] text-[#84948a]">
                                    <span x-show="loading">Loading...</span>
                                    <span x-show="!loading" x-text="episodes.length ? episodes.length + ' episodes' : 'Not available'"></span>
                                </p>
                            </div>
                            <div class="relative">
                                <span class="material-symbols-rounded pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base text-[#84948a]">search</span>
                                <input type="text" x-model="query" placeholder="Search episodes..."
                                       aria-label="Search episodes"
                                       class="h-9 w-44 rounded-xl border border-[#dcd2bd] bg-[#fbf9f4] pl-9 pr-3 text-xs text-[#172a37] placeholder:text-[#9aa29a] focus:border-[#0f766e] focus:bg-white focus:outline-none sm:w-52" />
                            </div>
                        </div>

                        <div x-show="episodes.length > perRange && query.trim() === ''" class="flex flex-wrap gap-1.5 border-b border-[#e0d9c8] px-4 py-3">
                            <template x-for="s in ranges" :key="s">
                                <button type="button" @click="rangeStart = s"
                                        class="rounded-full border px-3 py-1 font-mono text-[10px] font-semibold transition"
                                        :class="rangeStart === s ? 'border-[#0f766e] bg-[#0f766e] text-white' : 'border-[#dcd2bd] bg-white text-[#4c5b54] hover:border-[#0f766e]/40'"
                                        x-text="s + ' - ' + Math.min(s + perRange - 1, episodes.length)"></button>
                            </template>
                        </div>

                        <div class="p-4">
                            <div x-show="loading" class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                                <template x-for="i in 12" :key="i">
                                    <div class="h-12 animate-pulse rounded-xl bg-[#f1ebd8]"></div>
                                </template>
                            </div>

                            <div x-show="!loading && failed" x-cloak class="py-10 text-center">
                                <p class="text-sm font-medium text-[#6f7e75]">Failed to load the episode list from the streaming source.</p>
                                <button type="button" @click="load()" class="mt-3 rounded-xl bg-[#0f766e] px-4 py-2 text-xs font-bold text-white transition hover:bg-[#115e59]">Try again</button>
                            </div>

                            <div x-show="!loading && !failed && episodes.length === 0" x-cloak class="py-10 text-center">
                                <span class="material-symbols-rounded text-4xl text-[#c1cbbb]">live_tv</span>
                                <p class="mt-2 text-sm font-medium text-[#6f7e75]">Episodes are not available from the streaming source for this title.</p>
                            </div>

                            <div x-show="!loading && episodes.length > 0" x-cloak class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                                <template x-for="ep in visible" :key="ep.id">
                                    <a :href="'/anime/{{ $anime['id'] }}/watch?ep=' + encodeURIComponent(ep.id) + '&n=' + ep.number"
                                       class="group flex items-center gap-2.5 rounded-xl border border-[#eaddc2] bg-[#fbf9f4] px-3 py-2.5 transition hover:border-[#0f766e]/50 hover:bg-[#0f766e]/5">
                                        <span class="grid h-8 w-8 flex-shrink-0 place-items-center rounded-full bg-[#172a37] text-white transition group-hover:bg-[#f97316]">
                                            <span class="material-symbols-rounded text-base">play_arrow</span>
                                        </span>
                                        <span class="min-w-0">
                                            <span class="block font-mono text-xs font-semibold text-[#172a37]">EP <span x-text="ep.number"></span></span>
                                            <span class="block truncate text-[10px] text-[#84948a]" x-text="ep.title || ''"></span>
                                        </span>
                                    </a>
                                </template>
                            </div>
                        </div>
                    </section>
                    @else
                    <section class="rounded-2xl border border-[#e0d9c8] bg-white/85 p-8 text-center shadow-sm">
                        <span class="material-symbols-rounded text-4xl text-[#c1cbbb]">tv_off</span>
                        <p class="mt-2 text-sm font-bold text-[#172a37]">Episode list hidden</p>
                        <p class="mx-auto mt-1 max-w-md text-xs leading-relaxed text-[#6f7e75]">
                            This title has no video source on any provider. To avoid misleading you, the episode list and play buttons stay hidden until a digital release is available.
                        </p>
                        <a href="{{ route('anime.index') }}" class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-[#0f766e] px-4 py-2 text-xs font-bold text-white transition hover:bg-[#115e59]">
                            <span class="material-symbols-rounded text-base">arrow_back</span>
                            Back to anime catalog
                        </a>
                    </section>
                    @endif
                </div>

                {{-- Sidebar --}}
                <aside class="space-y-4 lg:sticky lg:top-[76px]">
                    <div class="rounded-2xl border border-[#e0d9c8] bg-white/85 p-4 shadow-sm">
                        <h2 class="font-display text-base font-bold text-[#172a37]">Information</h2>
                        <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-3">
                            <div>
                                <dt class="font-mono text-[9px] uppercase tracking-[0.14em] text-[#84948a]">Format</dt>
                                <dd class="mt-0.5 text-xs font-semibold text-[#172a37]">{{ $anime['format'] }}</dd>
                            </div>
                            <div>
                                <dt class="font-mono text-[9px] uppercase tracking-[0.14em] text-[#84948a]">Status</dt>
                                <dd class="mt-0.5 text-xs font-semibold text-[#172a37]">{{ $anime['status'] }}</dd>
                            </div>
                            <div>
                                <dt class="font-mono text-[9px] uppercase tracking-[0.14em] text-[#84948a]">Released</dt>
                                <dd class="mt-0.5 text-xs font-semibold text-[#172a37]">{{ $anime['year'] ?: '-' }}</dd>
                            </div>
                            <div>
                                <dt class="font-mono text-[9px] uppercase tracking-[0.14em] text-[#84948a]">Episode</dt>
                                <dd class="mt-0.5 font-mono text-xs font-semibold text-[#172a37]">{{ $anime['episodes'] ?: '-' }}</dd>
                            </div>
                            <div>
                                <dt class="font-mono text-[9px] uppercase tracking-[0.14em] text-[#84948a]">Duration</dt>
                                <dd class="mt-0.5 text-xs font-semibold text-[#172a37]">{{ $anime['duration'] ? $anime['duration'] . ' mnt' : '-' }}</dd>
                            </div>
                            <div>
                                <dt class="font-mono text-[9px] uppercase tracking-[0.14em] text-[#84948a]">Score</dt>
                                <dd class="mt-0.5 font-mono text-xs font-semibold text-[#172a37]">{{ $anime['score'] ?: '-' }}</dd>
                            </div>
                            @if(!empty($anime['studios']))
                                <div class="col-span-2">
                                    <dt class="font-mono text-[9px] uppercase tracking-[0.14em] text-[#84948a]">Studio</dt>
                                    <dd class="mt-0.5 text-xs font-semibold text-[#172a37]">{{ implode(', ', $anime['studios']) }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>

                    @if(!empty($anime['genres']))
                        <div class="rounded-2xl border border-[#e0d9c8] bg-white/85 p-4 shadow-sm">
                            <h2 class="font-display text-base font-bold text-[#172a37]">Genre</h2>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                @foreach($anime['genres'] as $genre)
                                    <a href="{{ route('anime.index', ['genre' => $genre]) }}"
                                       class="rounded-full border border-[#dcd2bd] bg-white/70 px-2.5 py-1 text-[11px] font-semibold text-[#4c5b54] transition hover:border-[#0f766e]/50 hover:text-[#0f766e]">{{ $genre }}</a>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if($available)
                        <a href="#episode-list"
                           class="flex w-full items-center justify-center gap-2 rounded-xl bg-[#0f766e] px-4 py-3 text-xs font-bold text-white shadow-sm transition hover:bg-[#115e59]">
                            <span class="material-symbols-rounded text-base">playlist_play</span>
                            Choose episode
                        </a>
                    @endif
                </aside>
            </div>
        </div>
    </div>
</x-manga-layout>
