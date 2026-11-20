<x-manga-layout :title="$manga['title'] . ' — Read Manga Online on BiblioManga'">
    <div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 space-y-8"
         x-data="{
            activeTab: @js($activeTab),
            expandedDesc: false,
            mangaId: @js($manga['id']),
            chaptersUrl: @js($chaptersUrl),
            chapterData: @js($chapterPayload),
            loading: @js(! $chaptersPreloaded),
            loadError: false,
            retries: 0,
            visibleLimit: 80,
            chapterQuery: '',
            sortAsc: false,
            init() {
                if (this.loading) {
                    this.fetchChapters();
                } else {
                    // Render the preloaded list instantly, then refresh from the
                    // (edge-cached) endpoint in the background so new chapters
                    // show up without waiting for the 12h aggregation TTL.
                    this.fetchChapters(false, true);
                }
            },
            async fetchChapters(silent = false, background = false) {
                if (!silent && !background) {
                    this.loading = true;
                }
                this.loadError = false;
                const base = this.chaptersUrl;
                const sep = base.includes('?') ? '&' : '?';
                const bust = silent ? '&_r=' + Date.now() : '';
                const payloads = [];
                const apply = () => {
                    if (!payloads.length) return;
                    const merged = this.mergePayloads(payloads);
                    // Never shrink a richer preloaded list while a background
                    // refresh is still waiting for its second language.
                    if (background && merged.counts.all < (this.chapterData.counts.all || 0)) {
                        return;
                    }
                    this.chapterData = merged;
                    if (!silent && !background) {
                        // Only pick the default tab during the initial load; never
                        // yank the user away from the tab they selected later.
                        if (this.chapterData.counts.id > 0) {
                            this.activeTab = 'id';
                        } else if (this.chapterData.counts.en > 0) {
                            this.activeTab = 'en';
                        }
                        this.loading = false;
                    }
                };
                const load = (lang) => fetch(
                    base + sep + 'lang=' + lang + bust,
                    { headers: { 'Accept': 'application/json' }, cache: silent ? 'no-store' : 'default' }
                ).then(response => {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.json();
                }).then(payload => {
                    payloads.push(payload);
                    apply();
                    return payload;
                });

                // Two parallel requests: each language only scrapes its own
                // sources, so a cold manga becomes readable in roughly half the
                // time. The first response is rendered immediately.
                const settled = await Promise.allSettled([load('id'), load('en')]);
                if (!payloads.length) {
                    if (!silent && !background) {
                        this.loading = false;
                        this.loadError = true;
                    }
                    return;
                }
                apply();
                // The server hit its time budget (or one language failed): poll
                // a couple of times so the list keeps filling in without a
                // manual refresh. Cached languages return instantly.
                const incomplete = this.chapterData.partial || settled.some(r => r.status === 'rejected');
                if (incomplete && !background && this.retries < 2) {
                    this.retries++;
                    setTimeout(() => this.fetchChapters(true), 5000 * this.retries);
                }
            },
            chapterKind(title) {
                const text = String(title || '').trim().toLowerCase();
                if (text === '') {
                    return 'main';
                }
                if (/^(extra|side\s*story|special|bonus)\b/.test(text)) {
                    return 'extra';
                }
                if (/^(chapter|ch\.?|episode|ep\.?)\b/.test(text)) {
                    return 'main';
                }
                return '';
            },
            mergePayloads(payloads) {
                const seen = new Set();
                const chapters = [];
                for (const payload of payloads) {
                    for (const chapter of (payload.chapters || [])) {
                        const key = (chapter.language || 'en') + '_' + (chapter.chapter || '0') + '_' + this.chapterKind(chapter.title);
                        if (seen.has(key)) {
                            continue;
                        }
                        seen.add(key);
                        chapters.push(chapter);
                    }
                }
                chapters.sort((a, b) => {
                    const na = parseFloat(a.chapter) || 0;
                    const nb = parseFloat(b.chapter) || 0;
                    if (na === nb) {
                        return String(a.language || '').localeCompare(String(b.language || ''));
                    }
                    return nb - na;
                });
                const counts = { all: 0, id: 0, en: 0 };
                for (const chapter of chapters) {
                    if (chapter.language === 'id') {
                        counts.id += 1;
                    } else if (chapter.language === 'en') {
                        counts.en += 1;
                    }
                }
                for (const payload of payloads) {
                    if (!payload.truncated) {
                        continue;
                    }
                    counts.id = Math.max(counts.id, (payload.counts && payload.counts.id) || 0);
                    counts.en = Math.max(counts.en, (payload.counts && payload.counts.en) || 0);
                }
                counts.all = counts.id + counts.en;
                // Keep every chapter the server sent. Truncating here to 400
                // is what made long series stop at the newest few hundred
                // entries even though the counter showed the real total.
                const visible = chapters;
                let first = null;
                let latest = null;
                for (const payload of payloads) {
                    if (payload.first) {
                        const n = parseFloat(payload.first.chapter) || 0;
                        if (!first || n < (parseFloat(first.chapter) || 0)) {
                            first = payload.first;
                        }
                    }
                    if (payload.latest) {
                        const n = parseFloat(payload.latest.chapter) || 0;
                        if (!latest || n > (parseFloat(latest.chapter) || 0)) {
                            latest = payload.latest;
                        }
                    }
                }
                return {
                    mangaId: this.mangaId,
                    chapters: visible,
                    counts: counts,
                    first: first || (chapters.length ? chapters[chapters.length - 1] : null),
                    latest: latest || (chapters.length ? chapters[0] : null),
                    truncated: false,
                    partial: payloads.some(payload => payload.partial),
                };
            },
            get counts() {
                return this.chapterData.counts || { all: 0, id: 0, en: 0 };
            },
            get filteredChapters() {
                let list = this.chapterData.chapters || [];
                if (this.activeTab !== 'all') {
                    list = list.filter(chapter => chapter.language === this.activeTab);
                }
                const query = this.chapterQuery.trim().toLowerCase();
                if (query !== '') {
                    list = list.filter(chapter => String(chapter.chapter || '').toLowerCase().includes(query)
                        || String(chapter.title || '').toLowerCase().includes(query));
                }
                return this.sortAsc ? [...list].reverse() : list;
            },
            get visibleChapters() {
                return this.filteredChapters.slice(0, this.visibleLimit);
            },
            get filteredCount() {
                return this.filteredChapters.length;
            },
            readUrl(chapter) {
                if (!chapter) return '#';
                if (chapter.externalUrl) return chapter.externalUrl;
                let url = '/manga/' + encodeURIComponent(this.mangaId)
                    + '/chapter/' + encodeURIComponent(chapter.id)
                    + '?source=' + encodeURIComponent(chapter.provider || 'MangaDex')
                    + '&ch=' + encodeURIComponent(chapter.chapter || '0');
                if (chapter.readPath) {
                    try {
                        url += '&p=' + encodeURIComponent(btoa(chapter.readPath));
                    } catch(e) {}
                }
                return url;
            }
         }">

        {{-- Back link --}}
        <div class="flex items-center gap-2 text-xs text-[#6f7e75]">
            <a href="{{ route('manga.index') }}" class="flex items-center gap-1 font-semibold transition hover:text-[#0f766e]">
                <span class="material-symbols-rounded text-sm">arrow_back</span>
                Catalog
            </a>
            <span class="text-[#c1cbbb]">/</span>
            <span class="max-w-[60vw] truncate font-medium text-[#172a37]">{{ $manga['title'] }}</span>
        </div>

        {{-- Detail hero --}}
        <section class="relative -mx-4 overflow-hidden border-b border-[#e0d9c8]/80 px-4 pb-7 pt-5 sm:-mx-6 sm:px-6 lg:-mx-7 lg:px-7 lg:pb-9 lg:pt-7">
            @if(!empty($manga['coverUrl']))
                <img src="{{ $manga['coverUrl'] }}" alt=""
                     class="absolute inset-0 h-full w-full scale-110 object-cover opacity-20 blur-[3px]" />
            @endif
            <div class="absolute inset-0 bg-gradient-to-r from-[#f6f1e8] via-[#f6f1e8]/92 to-[#f6f1e8]/40"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-[#f6f1e8] via-transparent to-transparent"></div>

            <div class="relative z-10 flex flex-col gap-5 sm:flex-row sm:items-end sm:gap-6">
                <div class="mx-auto w-32 flex-shrink-0 overflow-hidden rounded-2xl border-4 border-white bg-[#eaddc2] shadow-xl sm:mx-0 sm:w-40 lg:w-48">
                    <div class="relative aspect-[2/3] w-full">
                        @if(!empty($manga['coverUrl']))
                            <img src="{{ $manga['coverUrl'] }}" alt="{{ $manga['title'] }}" referrerpolicy="no-referrer"
                                 class="h-full w-full object-cover"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                            <div class="hidden h-full w-full flex-col items-center justify-center bg-gradient-to-br from-[#164e63] via-[#0f766e] to-[#134e4a] p-4 text-center text-white">
                                <span class="material-symbols-rounded mb-2 text-4xl text-[#fde68a]">auto_stories</span>
                                <span class="text-xs font-bold leading-snug">{{ $manga['title'] }}</span>
                            </div>
                        @else
                            <div class="flex h-full w-full flex-col items-center justify-center bg-gradient-to-br from-[#164e63] via-[#0f766e] to-[#134e4a] p-4 text-center text-white">
                                <span class="material-symbols-rounded mb-2 text-4xl text-[#fde68a]">auto_stories</span>
                                <span class="text-xs font-bold leading-snug">{{ $manga['title'] }}</span>
                            </div>
                        @endif
                        <span class="absolute left-2 top-2 rounded-full bg-[#0f766e]/95 px-2 py-0.5 font-mono text-[9px] font-bold uppercase tracking-wide text-white">
                            {{ $manga['status'] }}
                        </span>
                    </div>
                </div>

                <div class="min-w-0 flex-1 space-y-3 text-center sm:text-left">
                    <div class="flex flex-wrap items-center justify-center gap-2 sm:justify-start">
                        <span class="rounded-full bg-[#0f766e] px-2.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-white">{{ $manga['status'] }}</span>
                        <span class="rounded-full border border-[#dcd2bd] bg-white/80 px-2.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-[#4c5b54]">{{ $manga['format'] ?? 'Manga' }}</span>
                        @if(!empty($manga['contentRating']))
                            <span class="rounded-full border border-[#dcd2bd] bg-white/80 px-2.5 py-0.5 font-mono text-[10px] font-semibold uppercase tracking-wide text-[#4c5b54]">{{ $manga['contentRating'] }}</span>
                        @endif
                        @if(!empty($manga['year']))
                            <span class="font-mono text-[11px] text-[#84948a]">{{ $manga['year'] }}</span>
                        @endif
                    </div>

                    <h1 class="font-display text-2xl font-bold leading-tight tracking-tight text-[#172a37] sm:text-3xl lg:text-4xl">
                        {{ $manga['title'] }}
                    </h1>

                    @if(!empty($manga['altTitles']))
                        <p class="line-clamp-2 text-xs italic text-[#6f7e75]">
                            {{ implode(' • ', array_slice($manga['altTitles'], 0, 3)) }}
                        </p>
                    @endif

                    <div class="flex flex-wrap items-center justify-center gap-x-4 gap-y-1 text-xs text-[#4c5b54] sm:justify-start">
                        @if(!empty($manga['authors']))
                            <span><span class="text-[#84948a]">Author:</span> <strong class="font-semibold text-[#172a37]">{{ $manga['authors'] }}</strong></span>
                        @endif
                        @if(!empty($manga['artists']))
                            <span><span class="text-[#84948a]">Artist:</span> <strong class="font-semibold text-[#172a37]">{{ $manga['artists'] }}</strong></span>
                        @endif
                        <span class="font-mono text-[11px] text-[#84948a]"><span x-text="counts.all"></span> chapter</span>
                    </div>

                    @if(!empty($manga['tags']))
                        <div class="flex flex-wrap justify-center gap-1.5 sm:justify-start">
                            @foreach(array_slice($manga['tags'], 0, 8) as $tag)
                                <a href="{{ route('manga.index', ['genre' => $tag]) }}"
                                   class="rounded-full border border-[#dcd2bd] bg-white/70 px-2.5 py-1 text-[11px] font-semibold text-[#4c5b54] transition hover:border-[#0f766e]/50 hover:text-[#0f766e]">{{ $tag }}</a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px] lg:items-start">
            <div class="min-w-0 space-y-6">
                {{-- Synopsis --}}
                <section class="rounded-2xl border border-[#e0d9c8] bg-white/80 p-5 shadow-sm">
                    <h2 class="font-display text-lg font-bold text-[#172a37]">Synopsis</h2>
                    <div class="mt-2 text-sm leading-relaxed text-[#4c5b54]" :class="expandedDesc ? '' : 'line-clamp-3'">
                        {{ $manga['description'] }}
                    </div>
                    @if(strlen($manga['description']) > 200)
                        <button type="button" @click="expandedDesc = !expandedDesc"
                                class="mt-2 text-xs font-bold text-[#0f766e] hover:underline focus:outline-none"
                                x-text="expandedDesc ? 'Show less' : 'Read more'"></button>
                    @endif
                </section>

                {{-- Chapter panel --}}
                <section class="overflow-hidden rounded-2xl border border-[#e0d9c8] bg-white/85 shadow-sm">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-[#e0d9c8] p-4">
                        <div>
                            <h2 class="font-display text-lg font-bold text-[#172a37]">Chapter List</h2>
                            <p class="mt-0.5 font-mono text-[11px] text-[#84948a]"><span x-text="filteredCount"></span> chapters available</p>
                        </div>

                        <div class="flex items-center gap-1 rounded-full border border-[#dcd2bd] bg-[#fbf9f4] p-1">
                            <button type="button" @click="activeTab = 'id'"
                                    class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold transition"
                                    :class="activeTab === 'id' ? 'bg-[#0f766e] text-white shadow-sm' : 'text-[#4c5b54] hover:text-[#0f766e]'">
                                <span>ID</span>
                                <span class="rounded-full bg-black/10 px-1.5 font-mono text-[10px]" x-text="counts.id"></span>
                            </button>
                            <button type="button" @click="activeTab = 'en'"
                                    class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold transition"
                                    :class="activeTab === 'en' ? 'bg-[#0f766e] text-white shadow-sm' : 'text-[#4c5b54] hover:text-[#0f766e]'">
                                <span>EN</span>
                                <span class="rounded-full bg-black/10 px-1.5 font-mono text-[10px]" x-text="counts.en"></span>
                            </button>
                            <button type="button" @click="activeTab = 'all'"
                                    class="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold transition"
                                    :class="activeTab === 'all' ? 'bg-[#0f766e] text-white shadow-sm' : 'text-[#4c5b54] hover:text-[#0f766e]'">
                                <span>All</span>
                                <span class="rounded-full bg-black/10 px-1.5 font-mono text-[10px]" x-text="counts.all"></span>
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 border-b border-[#e0d9c8] px-4 py-3">
                        <label class="flex h-9 min-w-[160px] flex-1 items-center gap-2 rounded-xl border border-[#dcd2bd] bg-[#fbf9f4] px-3 focus-within:border-[#0f766e]">
                            <span class="material-symbols-rounded text-base text-[#84948a]">search</span>
                            <span class="sr-only">Search chapters</span>
                            <input type="search" x-model="chapterQuery" inputmode="numeric"
                                   placeholder="Search chapter number or title..."
                                   class="w-full border-0 bg-transparent p-0 text-xs font-medium text-[#172a37] placeholder:text-[#9aa29a] focus:outline-none focus:ring-0" />
                        </label>
                        <button type="button" @click="sortAsc = !sortAsc"
                                class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-[#dcd2bd] bg-[#fbf9f4] px-3 text-xs font-bold text-[#172a37] transition hover:border-[#0f766e]/50 hover:text-[#0f766e]">
                            <span class="material-symbols-rounded text-base">swap_vert</span>
                            <span class="font-mono text-[10px]" x-text="sortAsc ? 'ASC' : 'DESC'"></span>
                        </button>
                    </div>

                    <div class="max-h-[600px] space-y-1 overflow-y-auto p-2 custom-scroll"
                         @scroll="if (visibleLimit < filteredCount && $el.scrollTop + $el.clientHeight >= $el.scrollHeight - 250) visibleLimit += 100">

                        <div x-show="loading" x-cloak class="space-y-1.5 p-1">
                            <template x-for="i in 6" :key="'skeleton-' + i">
                                <div class="flex animate-pulse items-center justify-between gap-4 rounded-xl border border-[#eaddc2] bg-[#fbf9f4] p-3">
                                    <div class="min-w-0 flex-1 space-y-2">
                                        <div class="h-3.5 w-32 rounded bg-[#e6dfcd]"></div>
                                        <div class="h-3 w-24 rounded bg-[#ece6d7]"></div>
                                    </div>
                                    <div class="h-7 w-16 rounded-lg bg-[#e6dfcd]"></div>
                                </div>
                            </template>
                        </div>

                        <div x-show="!loading && loadError" x-cloak class="px-4 py-12 text-center">
                            <span class="material-symbols-rounded text-4xl text-[#c1cbbb]">cloud_off</span>
                            <p class="mt-2 text-sm font-medium text-[#6f7e75]">Failed to load the chapter list.</p>
                            <button type="button" @click="fetchChapters()"
                                    class="mt-3 inline-flex items-center gap-1.5 rounded-xl bg-[#0f766e] px-4 py-2 text-xs font-bold text-white transition hover:bg-[#115e59]">
                                <span class="material-symbols-rounded text-sm">refresh</span>
                                Try again
                            </button>
                        </div>

                        <div x-show="!loading && !loadError && filteredCount === 0" x-cloak
                             class="px-4 py-12 text-center text-sm text-[#84948a]">
                            No chapters for this filter.
                        </div>

                        <div x-show="!loading && !loadError" x-cloak>
                            <template x-for="chap in visibleChapters" :key="chap.language + '-' + chap.id + '-' + chap.chapter">
                                <div @mouseenter="prefetchRead(readUrl(chap))"
                                     @touchstart.passive="prefetchRead(readUrl(chap))"
                                     class="group flex items-center gap-3 rounded-xl px-3 py-2.5 transition hover:bg-[#f6f1e8]">
                                    <span class="w-16 flex-shrink-0 font-mono text-xs font-semibold text-[#0f766e]">
                                        CH <span x-text="chap.chapter"></span>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex min-w-0 items-center gap-2">
                                            <span class="truncate text-xs font-semibold text-[#172a37] group-hover:text-[#0f766e]" x-text="chap.title || ('Chapter ' + chap.chapter)"></span>
                                            <span class="flex-shrink-0 rounded-md bg-[#0f766e]/10 px-1.5 py-0.5 font-mono text-[9px] font-semibold uppercase text-[#0f766e]"
                                                  x-text="chap.language === 'id' ? 'ID' : (chap.language === 'en' ? 'EN' : (chap.language || '').toUpperCase())"></span>
                                        </div>
                                        <div class="mt-0.5 flex items-center gap-2 font-mono text-[10px] text-[#84948a]">
                                            <span x-text="chap.publishAt"></span>
                                        </div>
                                    </div>
                                    <a :href="readUrl(chap)" :target="chap.externalUrl ? '_blank' : null"
                                       class="inline-flex flex-shrink-0 items-center gap-1 rounded-lg bg-[#0f766e]/10 px-3 py-1.5 text-[11px] font-bold text-[#0f766e] transition group-hover:bg-[#0f766e] group-hover:text-white">
                                        <span x-text="chap.externalUrl ? 'Open' : 'Read'"></span>
                                        <span class="material-symbols-rounded text-sm" x-text="chap.externalUrl ? 'open_in_new' : 'arrow_forward'"></span>
                                    </a>
                                </div>
                            </template>
                        </div>

                        <div x-show="!loading && !loadError && filteredCount > visibleLimit" x-cloak class="pt-2 text-center">
                            <button type="button" @click="visibleLimit += 100"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-[#dcd2bd] bg-[#fbf9f4] px-5 py-2 text-xs font-bold text-[#0f766e] transition hover:bg-[#0f766e]/10">
                                <span class="material-symbols-rounded text-sm">expand_more</span>
                                Load more
                            </button>
                        </div>
                    </div>
                </section>
            </div>

            {{-- Sidebar --}}
            <aside class="space-y-4 lg:sticky lg:top-[76px]">
                <div class="rounded-2xl border border-[#e0d9c8] bg-white/85 p-4 shadow-sm">
                    <h2 class="font-display text-base font-bold text-[#172a37]">Information</h2>
                    <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-3">
                        <div>
                            <dt class="font-mono text-[9px] uppercase tracking-[0.14em] text-[#84948a]">Format</dt>
                            <dd class="mt-0.5 text-xs font-semibold text-[#172a37]">{{ $manga['format'] ?? 'Manga' }}</dd>
                        </div>
                        <div>
                            <dt class="font-mono text-[9px] uppercase tracking-[0.14em] text-[#84948a]">Status</dt>
                            <dd class="mt-0.5 text-xs font-semibold text-[#172a37]">{{ $manga['status'] }}</dd>
                        </div>
                        <div>
                            <dt class="font-mono text-[9px] uppercase tracking-[0.14em] text-[#84948a]">Released</dt>
                            <dd class="mt-0.5 text-xs font-semibold text-[#172a37]">{{ $manga['year'] ?: '-' }}</dd>
                        </div>
                        <div>
                            <dt class="font-mono text-[9px] uppercase tracking-[0.14em] text-[#84948a]">Rating</dt>
                            <dd class="mt-0.5 text-xs font-semibold text-[#172a37]">{{ $manga['contentRating'] ?: 'Safe' }}</dd>
                        </div>
                        <div>
                            <dt class="font-mono text-[9px] uppercase tracking-[0.14em] text-[#84948a]">Chapter</dt>
                            <dd class="mt-0.5 font-mono text-xs font-semibold text-[#172a37]" x-text="counts.all"></dd>
                        </div>
                        <div>
                            <dt class="font-mono text-[9px] uppercase tracking-[0.14em] text-[#84948a]">Author</dt>
                            <dd class="mt-0.5 truncate text-xs font-semibold text-[#172a37]" title="{{ $manga['authors'] ?? '-' }}">{{ $manga['authors'] ?: '-' }}</dd>
                        </div>
                    </dl>
                </div>

                <button type="button"
                        @click="toggleFav({ id: '{{ $manga['id'] }}', title: '{{ addslashes($manga['title']) }}', coverUrl: '{{ $manga['coverUrl'] }}', status: '{{ addslashes($manga['status']) }}' })"
                        class="flex w-full items-center justify-center gap-2 rounded-xl border px-4 py-3 text-xs font-bold transition"
                        :class="isFav('{{ $manga['id'] }}') ? 'border-[#f97316] bg-[#f97316] text-white shadow-md' : 'border-[#f97316]/30 bg-[#f97316]/10 text-[#ea580c] hover:bg-[#f97316]/15'">
                    <span class="material-symbols-rounded text-base" x-text="isFav('{{ $manga['id'] }}') ? 'bookmark_added' : 'bookmark_add'"></span>
                    <span x-text="isFav('{{ $manga['id'] }}') ? 'Saved to favorites' : 'Add to favorites'"></span>
                </button>

                <div class="space-y-2 rounded-2xl border border-[#e0d9c8] bg-white/85 p-4 shadow-sm">
                    <h2 class="font-display text-base font-bold text-[#172a37]">Start reading</h2>
                    <template x-if="chapterData.first">
                        <a :href="readUrl(chapterData.first)"
                           class="flex w-full items-center justify-center gap-2 rounded-xl bg-[#0f766e] px-4 py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-[#115e59]">
                            <span class="material-symbols-rounded text-base">play_arrow</span>
                            First chapter <span class="font-mono" x-text="chapterData.first.chapter"></span>
                        </a>
                    </template>
                    <template x-if="chapterData.latest && (!chapterData.first || chapterData.latest.id !== chapterData.first.id)">
                        <a :href="readUrl(chapterData.latest)"
                           class="flex w-full items-center justify-center gap-2 rounded-xl border border-[#dcd2bd] bg-white px-4 py-2.5 text-xs font-bold text-[#172a37] transition hover:border-[#0f766e]/50 hover:text-[#0f766e]">
                            <span class="material-symbols-rounded text-base">skip_next</span>
                            Latest chapter <span class="font-mono" x-text="chapterData.latest.chapter"></span>
                        </a>
                    </template>
                </div>
            </aside>
        </div>


    <script>
    window.prefetchRead = function(url) {
        if (!url || window.__prefetchedReads?.[url]) return;
        window.__prefetchedReads = window.__prefetchedReads || {};
        window.__prefetchedReads[url] = true;
        const link = document.createElement('link');
        link.rel = 'prefetch';
        link.href = url;
        document.head.appendChild(link);
    };
    </script>
</x-manga-layout>
