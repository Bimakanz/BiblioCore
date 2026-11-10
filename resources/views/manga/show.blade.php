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
                const visible = chapters.slice(0, 400);
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
                    truncated: chapters.length > visible.length,
                    partial: payloads.some(payload => payload.partial),
                };
            },
            get counts() {
                return this.chapterData.counts || { all: 0, id: 0, en: 0 };
            },
            get filteredChapters() {
                const list = this.chapterData.chapters || [];
                if (this.activeTab === 'all') {
                    return list;
                }
                return list.filter(chapter => chapter.language === this.activeTab);
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

        <!-- Navigation Breadcrumb -->
        <div class="flex items-center gap-2 text-xs text-[#6f7e75]">
            <a href="{{ route('manga.index') }}" class="hover:text-[#0f766e] flex items-center gap-1 font-semibold">
                <span class="material-symbols-rounded text-sm">arrow_back</span>
                Back to Catalog
            </a>
            <span>•</span>
            <span class="truncate max-w-xs text-[#172a37] font-medium">{{ $manga['title'] }}</span>
        </div>

        <!-- Manga Detail Card -->
        <section class="relative overflow-hidden rounded-[2.5rem] border border-[#dcd2bd] bg-white/95 p-6 sm:p-10 shadow-[0_20px_60px_-35px_rgba(15,87,82,0.35)]">
            <div class="grid gap-8 md:grid-cols-[260px_1fr] lg:grid-cols-[300px_1fr]">

                <!-- Left: Poster Cover -->
                <div class="space-y-4">
                    <div class="relative aspect-[3/4] w-full overflow-hidden rounded-[2rem] bg-[#eaddc2] shadow-lg border border-[#eaddc2]">
                        @if($manga['coverUrl'])
                            <img src="{{ $manga['coverUrl'] }}" alt="{{ $manga['title'] }}"
                                 referrerpolicy="no-referrer"
                                 class="h-full w-full object-cover"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
                            <div class="hidden h-full w-full flex-col items-center justify-center bg-gradient-to-br from-[#164e63] via-[#0f766e] to-[#134e4a] p-6 text-center text-white">
                                <span class="material-symbols-rounded text-5xl text-[#fde68a] mb-2">auto_stories</span>
                                <span class="text-sm font-bold leading-snug">{{ $manga['title'] }}</span>
                            </div>
                        @else
                            <div class="flex h-full w-full flex-col items-center justify-center bg-gradient-to-br from-[#164e63] via-[#0f766e] to-[#134e4a] p-6 text-center text-white">
                                <span class="material-symbols-rounded text-5xl text-[#fde68a] mb-2">auto_stories</span>
                                <span class="text-sm font-bold leading-snug">{{ $manga['title'] }}</span>
                            </div>
                        @endif

                        <div class="absolute top-3 left-3 inline-flex items-center rounded-full bg-black/60 backdrop-blur-md px-3 py-1 text-xs font-bold uppercase tracking-wider text-white">
                            {{ $manga['status'] }}
                        </div>
                    </div>

                    <!-- Bookmark Button -->
                    <button type="button"
                            @click="toggleFav({ id: '{{ $manga['id'] }}', title: '{{ addslashes($manga['title']) }}', coverUrl: '{{ $manga['coverUrl'] }}', status: '{{ $manga['status'] }}' })"
                            class="w-full flex items-center justify-center gap-2 rounded-2xl py-3 px-4 font-bold text-xs uppercase tracking-wider transition border"
                            :class="isFav('{{ $manga['id'] }}') ? 'bg-[#f97316] text-white border-[#f97316] shadow-md' : 'bg-[#fbf9f4] text-[#ea580c] border-[#f97316]/30 hover:bg-[#f97316]/10'">
                        <span class="material-symbols-rounded text-base" x-text="isFav('{{ $manga['id'] }}') ? 'bookmark_added' : 'bookmark_add'"></span>
                        <span x-text="isFav('{{ $manga['id'] }}') ? 'Saved to Favorites' : 'Save to Favorites'"></span>
                    </button>
                </div>

                <!-- Right: Meta Information -->
                <div class="flex flex-col justify-between space-y-6">
                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full bg-[#0f766e]/10 px-3 py-1 text-xs font-bold uppercase tracking-wider text-[#0f766e]">
                                {{ $manga['contentRating'] }}
                            </span>
                            @if($manga['year'])
                                <span class="rounded-full bg-black/5 px-3 py-1 text-xs font-bold text-[#5f6b63]">
                                    Released: {{ $manga['year'] }}
                                </span>
                            @endif
                        </div>

                        <h1 class="text-2xl sm:text-4xl font-extrabold text-[#172a37] tracking-tight leading-tight" style="font-family: 'Space Grotesk', sans-serif;">
                            {{ $manga['title'] }}
                        </h1>

                        @if(!empty($manga['altTitles']))
                            <p class="text-xs text-[#6f7e75] italic line-clamp-2">
                                Alternative names: {{ implode(' • ', array_slice($manga['altTitles'], 0, 3)) }}
                            </p>
                        @endif

                        <div class="flex flex-wrap gap-4 text-xs text-[#4c5b54] pt-1 border-t border-[#e0d9c8]">
                            <div>
                                <span class="text-[#84948a] uppercase tracking-wider">Author:</span>
                                <span class="font-semibold text-[#172a37]">{{ $manga['authors'] ?? '-' }}</span>
                            </div>
                            <div>
                                <span class="text-[#84948a] uppercase tracking-wider">Illustrator:</span>
                                <span class="font-semibold text-[#172a37]">{{ $manga['artists'] ?? '-' }}</span>
                            </div>
                        </div>

                        <!-- Genre Tags -->
                        @if(!empty($manga['tags']))
                            <div class="flex flex-wrap gap-1.5 pt-2">
                                @foreach($manga['tags'] as $tag)
                                    <span class="rounded-full border border-[#dcd2bd] bg-[#fbf9f4] px-3 py-1 text-xs font-medium text-[#4c5b54]">
                                        {{ $tag }}
                                    </span>
                                @endforeach
                            </div>
                        @endif

                        <!-- Synopsis -->
                        <div class="pt-2">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-[#84948a]">Synopsis</h3>
                            <div class="mt-1 text-sm leading-relaxed text-[#4c5b54] relative"
                                 :class="expandedDesc ? '' : 'line-clamp-3'">
                                {{ $manga['description'] }}
                            </div>
                            @if(strlen($manga['description']) > 200)
                                <button type="button" @click="expandedDesc = !expandedDesc"
                                        class="mt-1 text-xs font-bold text-[#0f766e] hover:underline focus:outline-none"
                                        x-text="expandedDesc ? 'Show Less' : 'Read More...'">
                                </button>
                            @endif
                        </div>
                    </div>

                    <!-- Quick CTA Start Reading (filled after the async chapter list arrives) -->
                    <div class="flex flex-wrap items-center gap-3 pt-6 border-t border-[#e0d9c8]">
                        <template x-if="chapterData.first">
                            <a :href="readUrl(chapterData.first)"
                               class="inline-flex items-center gap-2 rounded-full bg-[#0f766e] px-6 py-3 text-xs sm:text-sm font-bold uppercase tracking-wider text-white shadow-lg shadow-[#0f766e]/25 transition hover:scale-105 hover:bg-[#115e59]">
                                <span class="material-symbols-rounded text-base">play_arrow</span>
                                <span>Read First Chapter (Ch. <span x-text="chapterData.first.chapter"></span>)</span>
                            </a>
                        </template>

                        <template x-if="chapterData.latest && (!chapterData.first || chapterData.latest.id !== chapterData.first.id)">
                            <a :href="readUrl(chapterData.latest)"
                               class="inline-flex items-center gap-2 rounded-full border border-[#dcd2bd] bg-white px-5 py-3 text-xs sm:text-sm font-bold uppercase tracking-wider text-[#172a37] transition hover:border-[#0f766e] hover:text-[#0f766e]">
                                <span class="material-symbols-rounded text-base">skip_next</span>
                                <span>Latest Chapter (Ch. <span x-text="chapterData.latest.chapter"></span>)</span>
                            </a>
                        </template>
                    </div>
                </div>

            </div>
        </section>

        <!-- Chapter List Section -->
        <section class="rounded-[2.5rem] border border-[#dcd2bd] bg-white p-6 sm:p-8 shadow-sm space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[#e0d9c8] pb-4">
                <div>
                    <h2 class="text-xl sm:text-2xl font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">
                        Chapter List
                    </h2>
                    <p class="text-xs text-[#6f7e75] mt-1">
                        Choose the chapter you want to read in Indonesian or English
                    </p>
                </div>

                <!-- Language Tabs -->
                <div class="flex items-center gap-1.5 rounded-full border border-[#dcd2bd] bg-[#fbf9f4] p-1">
                    <button type="button" @click="activeTab = 'id'"
                            class="rounded-full px-3.5 py-1.5 text-xs font-bold transition flex items-center gap-1.5"
                            :class="activeTab === 'id' ? 'bg-[#0f766e] text-white shadow-sm' : 'text-[#4c5b54] hover:text-[#0f766e]'">
                        <span>Indonesian</span>
                        <span class="rounded-full bg-black/10 dark:bg-white/20 px-1.5 text-[10px]" x-text="counts.id"></span>
                    </button>
                    <button type="button" @click="activeTab = 'en'"
                            class="rounded-full px-3.5 py-1.5 text-xs font-bold transition flex items-center gap-1.5"
                            :class="activeTab === 'en' ? 'bg-[#0f766e] text-white shadow-sm' : 'text-[#4c5b54] hover:text-[#0f766e]'">
                        <span>English</span>
                        <span class="rounded-full bg-black/10 dark:bg-white/20 px-1.5 text-[10px]" x-text="counts.en"></span>
                    </button>
                    <button type="button" @click="activeTab = 'all'"
                            class="rounded-full px-3.5 py-1.5 text-xs font-bold transition flex items-center gap-1.5"
                            :class="activeTab === 'all' ? 'bg-[#0f766e] text-white shadow-sm' : 'text-[#4c5b54] hover:text-[#0f766e]'">
                        <span>All</span>
                        <span class="rounded-full bg-black/10 dark:bg-white/20 px-1.5 text-[10px]" x-text="counts.all"></span>
                    </button>
                </div>
            </div>

            <!-- Chapter Table / List -->
            <div class="space-y-2 max-h-[600px] overflow-y-auto pr-1 custom-scroll">

                <!-- Skeleton while the async endpoint aggregates sources -->
                <div x-show="loading" x-cloak class="space-y-2">
                    <template x-for="i in 6" :key="'skeleton-' + i">
                        <div class="animate-pulse flex items-center justify-between gap-4 rounded-2xl border border-[#eaddc2] bg-[#fbf9f4] p-3.5">
                            <div class="min-w-0 flex-1 space-y-2">
                                <div class="h-4 w-32 rounded bg-[#e6dfcd]"></div>
                                <div class="h-3 w-24 rounded bg-[#ece6d7]"></div>
                            </div>
                            <div class="h-8 w-20 rounded-xl bg-[#e6dfcd]"></div>
                        </div>
                    </template>
                </div>

                <!-- Error state -->
                <div x-show="!loading && loadError" x-cloak class="py-12 text-center">
                    <span class="material-symbols-rounded text-4xl text-[#c1cbbb]">cloud_off</span>
                    <p class="mt-2 text-sm font-medium text-[#6f7e75]">Failed to load chapter list.</p>
                    <button type="button" @click="fetchChapters()"
                            class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-[#0f766e] px-4 py-2 text-xs font-bold uppercase tracking-wider text-white transition hover:bg-[#115e59]">
                        <span class="material-symbols-rounded text-sm">refresh</span>
                        Try Again
                    </button>
                </div>

                <!-- Empty state -->
                <div x-show="!loading && !loadError && filteredCount === 0" x-cloak
                     class="py-12 text-center text-[#84948a] text-sm">
                    No chapters available for this language.
                </div>

                <!-- Rendered chapters -->
                <div x-show="!loading && !loadError" x-cloak class="space-y-2">
                    <template x-for="chap in visibleChapters" :key="chap.language + '-' + chap.id + '-' + chap.chapter">
                        <div @mouseenter="prefetchRead(readUrl(chap))"
                             @touchstart.passive="prefetchRead(readUrl(chap))"
                             class="group flex items-center justify-between gap-4 rounded-2xl border border-[#eaddc2] bg-[#fbf9f4] p-3.5 transition hover:border-[#0f766e] hover:bg-white hover:shadow-sm">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-bold text-sm text-[#172a37] group-hover:text-[#0f766e] transition-colors">
                                        Chapter <span x-text="chap.chapter"></span>
                                    </span>
                                    <span class="rounded-md bg-[#0f766e]/10 px-1.5 py-0.5 text-[10px] font-bold text-[#0f766e]"
                                          x-text="chap.language === 'id' ? 'Indonesian' : (chap.language === 'en' ? 'English' : (chap.language || '').toUpperCase())"></span>
                                    <span x-show="chap.title" x-text="chap.title"
                                          class="text-xs text-[#6f7e75] truncate max-w-xs font-medium"></span>
                                </div>
                                <div class="mt-1 flex items-center gap-3 text-[11px] text-[#84948a]">
                                    <span x-text="chap.publishAt"></span>
                                </div>
                            </div>
                            <a :href="readUrl(chap)" :target="chap.externalUrl ? '_blank' : null"
                               class="inline-flex items-center gap-1 rounded-xl bg-[#0f766e] px-4 py-1.5 text-xs font-bold uppercase tracking-wider text-white shadow-sm transition hover:bg-[#115e59]">
                                <span x-text="chap.externalUrl ? 'Open External' : 'Read'"></span>
                                <span class="material-symbols-rounded text-sm"
                                      x-text="chap.externalUrl ? 'open_in_new' : 'arrow_forward'"></span>
                            </a>
                        </div>
                    </template>
                </div>

                <!-- Load more -->
                <div x-show="!loading && !loadError && filteredCount > visibleLimit" x-cloak class="pt-2 text-center">
                    <button type="button" @click="visibleLimit += 100"
                            class="inline-flex items-center gap-1.5 rounded-full border border-[#dcd2bd] bg-[#fbf9f4] px-5 py-2 text-xs font-bold text-[#0f766e] transition hover:bg-[#0f766e]/10">
                        <span class="material-symbols-rounded text-sm">expand_more</span>
                        Show more
                    </button>
                </div>
            </div>
        </section>

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
