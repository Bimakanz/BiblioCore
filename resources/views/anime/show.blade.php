<x-manga-layout title="{{ $anime['title'] }} — BiblioAnime">
    <div class="relative">
        {{-- Banner --}}
        <div class="relative h-52 sm:h-72 overflow-hidden bg-[#172a37]">
            @if($anime['bannerUrl'])
                <img src="{{ $anime['bannerUrl'] }}" alt="" class="h-full w-full object-cover" />
            @else
                <img src="{{ $anime['coverUrl'] }}" alt="" class="h-full w-full object-cover opacity-40 blur-[2px] scale-105" />
            @endif
            <div class="absolute inset-0 bg-gradient-to-t from-[#f6f1e8] via-transparent to-transparent"></div>
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
                                Episode {{ $anime['nextAiring']['episode'] }} tayang dalam {{ (int) floor($anime['nextAiring']['timeUntilAiring'] / 86400) }} hari {{ (int) floor(($anime['nextAiring']['timeUntilAiring'] % 86400) / 3600) }} jam
                            </span>
                        @endif
                    </div>
                    <h1 class="text-2xl sm:text-4xl font-extrabold tracking-tight text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">{{ $anime['title'] }}</h1>
                    @if(!empty($anime['altTitles']))
                        <p class="text-xs text-[#84948a] line-clamp-1">{{ implode(' &middot; ', array_slice($anime['altTitles'], 0, 3)) }}</p>
                    @endif
                    <div class="flex flex-wrap items-center justify-center sm:justify-start gap-x-4 gap-y-1 text-sm font-semibold text-[#4c5b54]">
                        @if($anime['score'])<span class="inline-flex items-center gap-1"><span class="material-symbols-rounded text-base text-[#f59e0b]">star</span>{{ $anime['score'] }}/10</span>@endif
                        @if($anime['year'])<span>{{ $anime['year'] }}</span>@endif
                        @if($anime['episodes'])<span>{{ $anime['episodes'] }} Episode</span>@endif
                        @if($anime['duration'])<span>{{ $anime['duration'] }} menit/episode</span>@endif
                        @if(!empty($anime['studios']))<span>{{ implode(', ', $anime['studios']) }}</span>@endif
                    </div>
                    @if(!empty($anime['genres']))
                        <div class="flex flex-wrap justify-center sm:justify-start gap-1.5">
                            @foreach($anime['genres'] as $genre)
                                <a href="{{ route('anime.index', ['genre' => $genre]) }}" class="rounded-full bg-white border border-[#dcd2bd] px-3 py-1 text-[11px] font-semibold text-[#4c5b54] transition hover:border-[#0f766e]/50 hover:text-[#0f766e]">{{ $genre }}</a>
                            @endforeach
                        </div>
                    @endif

                    {{-- Resume (client-side, from biblioanime_history) --}}
                    <div x-data="{ entry: (JSON.parse(localStorage.getItem('biblioanime_history') || '[]')).find(h => h.id === '{{ $anime['id'] }}') || null }" x-cloak>
                        <template x-if="entry">
                            <a :href="'/anime/{{ $anime['id'] }}/watch?ep=' + encodeURIComponent(entry.episodeId || '') + '&n=' + encodeURIComponent(entry.episodeNumber || 1) + '&t=' + Math.max(0, Math.floor((entry.positionSec || 0) - 5))"
                               class="inline-flex items-center gap-2 rounded-full bg-[#f97316] px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-[#ea580c]">
                                <span class="material-symbols-rounded">resume</span>
                                Lanjut Episode <span x-text="entry.episodeNumber"></span>
                            </a>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Title names --}}
            @if(!empty($anime['titles']) || !empty($anime['synonyms']))
                <section class="mt-8 rounded-3xl border border-[#e0d9c8] bg-white p-6 shadow-sm">
                    <h2 class="mb-3 text-base font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">Nama Judul</h2>
                    <dl class="space-y-2.5 text-sm">
                        @if(!empty($anime['titles']['native']))
                            <div class="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
                                <dt class="w-24 flex-shrink-0 font-bold text-[#172a37]">Judul Asli</dt>
                                <dd class="text-[#4c5b54]">{{ $anime['titles']['native'] }}</dd>
                            </div>
                        @endif
                        @if(!empty($anime['titles']['english']))
                            <div class="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
                                <dt class="w-24 flex-shrink-0 font-bold text-[#172a37]">English</dt>
                                <dd class="text-[#4c5b54]">{{ $anime['titles']['english'] }}</dd>
                            </div>
                        @endif
                        @if(!empty($anime['titles']['romaji']))
                            <div class="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
                                <dt class="w-24 flex-shrink-0 font-bold text-[#172a37]">Romaji</dt>
                                <dd class="text-[#4c5b54]">{{ $anime['titles']['romaji'] }}</dd>
                            </div>
                        @endif
                        @if(!empty($anime['synonyms']))
                            <div class="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
                                <dt class="w-24 flex-shrink-0 font-bold text-[#172a37]">Sinonim</dt>
                                <dd class="leading-relaxed text-[#4c5b54]">{{ implode(', ', $anime['synonyms']) }}</dd>
                            </div>
                        @endif
                    </dl>
                </section>
            @endif

            {{-- Synopsis --}}
            <section class="mt-8 rounded-3xl border border-[#e0d9c8] bg-white p-6 shadow-sm">
                <h2 class="mb-2 text-base font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">Sinopsis</h2>
                <p class="text-sm leading-relaxed text-[#4c5b54] whitespace-pre-line">{{ $anime['description'] }}</p>
            </section>

            {{-- Episodes (async) --}}
            <section class="mt-6 mb-14 rounded-3xl border border-[#e0d9c8] bg-white p-6 shadow-sm"
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
                <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-base font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">
                        Daftar Episode <span class="text-[#84948a] font-semibold" x-show="!loading" x-text="episodes.length ? '(' + episodes.length + ')' : ''"></span>
                    </h2>
                    <div class="relative">
                        <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-base text-[#84948a]">search</span>
                        <input type="text" x-model="query" placeholder="Cari nomor episode..."
                               class="w-52 rounded-full border border-[#dcd2bd] bg-[#fbf9f4] py-1.5 pl-9 pr-3 text-xs text-[#172a37] placeholder:text-[#9aa29a] focus:border-[#0f766e] focus:bg-white focus:outline-none" />
                    </div>
                </div>

                <div x-show="episodes.length > perRange && query.trim() === ''" class="mb-4 flex flex-wrap gap-1.5">
                    <template x-for="s in ranges" :key="s">
                        <button type="button" @click="rangeStart = s"
                                class="rounded-full px-3 py-1 text-[11px] font-bold border transition"
                                :class="rangeStart === s ? 'bg-[#0f766e] text-white border-[#0f766e]' : 'bg-white text-[#4c5b54] border-[#dcd2bd] hover:border-[#0f766e]/40'"
                                x-text="s + ' - ' + Math.min(s + perRange - 1, episodes.length)"></button>
                    </template>
                </div>

                <div x-show="loading" class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    <template x-for="i in 12" :key="i">
                        <div class="h-12 animate-pulse rounded-2xl bg-[#f1ebd8]"></div>
                    </template>
                </div>

                <div x-show="!loading && failed" x-cloak class="py-10 text-center">
                    <p class="text-sm font-medium text-[#6f7e75]">Daftar episode gagal dimuat dari sumber streaming.</p>
                    <button type="button" @click="load()" class="mt-3 rounded-full bg-[#0f766e] px-4 py-2 text-xs font-bold text-white transition hover:bg-[#115e59]">Coba Lagi</button>
                </div>

                <div x-show="!loading && !failed && episodes.length === 0" x-cloak class="py-10 text-center">
                    <span class="material-symbols-rounded text-4xl text-[#c1cbbb]">live_tv</span>
                    <p class="mt-2 text-sm font-medium text-[#6f7e75]">Episode belum tersedia di sumber streaming untuk judul ini.</p>
                </div>

                <div x-show="!loading && episodes.length > 0" x-cloak class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    <template x-for="ep in visible" :key="ep.id">
                        <a :href="'/anime/{{ $anime['id'] }}/watch?ep=' + encodeURIComponent(ep.id) + '&n=' + ep.number"
                           class="group flex items-center gap-3 rounded-2xl border border-[#eaddc2] bg-[#fbf9f4] px-4 py-3 transition hover:border-[#0f766e]/50 hover:bg-[#0f766e]/5">
                            <span class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-[#172a37] text-white transition group-hover:bg-[#f97316]">
                                <span class="material-symbols-rounded text-lg">play_arrow</span>
                            </span>
                            <span class="min-w-0">
                                <span class="block text-sm font-bold text-[#172a37]">Episode <span x-text="ep.number"></span></span>
                                <span class="block truncate text-[11px] text-[#84948a]" x-text="ep.title || ''"></span>
                            </span>
                        </a>
                    </template>
                </div>
            </section>
        </div>
    </div>
</x-manga-layout>
