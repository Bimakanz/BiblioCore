<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ch. {{ $currentChapter['chapter'] }} — {{ $manga['title'] }} | BiblioManga</title>

    {{-- Animated site icon. The ICO is the static fallback for Safari. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/gif" href="{{ asset('favicon.gif') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:wght@300;400;500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        [x-cloak] { display: none !important; }
        body { background-color: #08090a; color: #e2e8f0; }
        .reader-scroll::-webkit-scrollbar { width: 5px; }
        .reader-scroll::-webkit-scrollbar-track { background: #16181d; }
        .reader-scroll::-webkit-scrollbar-thumb { background: #0f766e; border-radius: 9999px; }
    </style>
</head>
<body x-data="{
        mangaId: @js($manga['id']),
        mangaTitle: @js($manga['title']),
        mangaCoverUrl: @js($manga['coverUrl']),
        mode: localStorage.getItem('reader_mode') || 'paged',
        widthMode: localStorage.getItem('reader_width') || 'normal',
        quality: localStorage.getItem('reader_quality') || 'hd',
        handMode: localStorage.getItem('reader_hand_mode') || 'right', // 'right' (default) or 'left'
        currentPage: 1,
        pages: @js($pages),
        totalPages: {{ $totalPages }},
        currentChapter: @js($currentChapter),
        prevChapter: @js($prevChapter),
        nextChapter: @js($nextChapter),
        chapters: @js($sameLangChapters),
        chaptersUrl: @js(route('manga.chapters.json', ['id' => $manga['id']]) . '?lang=' . ($currentChapter['language'] ?? 'en')),
        chaptersLoading: false,
        chaptersTries: 0,
        autoContinueWhenLoaded: false,
        latestChapNum: {{ !empty($sameLangChapters[0]['chapter']) ? (int)$sameLangChapters[0]['chapter'] : (count($sameLangChapters) ?: 1) }},
        prefetchedNextChapter: null,
        prefetchedPrevChapter: null,
        progress: 0,
        showMenu: false,
        showChapterList: false,
        chapterLimit: 60,
        autoNextArmed: false,
        autoNextDone: false,
        bottomSince: 0,
        isFullscreen: false,
        toastOpen: false,
        toastMsg: '',
        
        init() {
            this.totalPages = this.pages.length;
            this.fillAdjacentFromList();
            // Opening straight from history lands on a chapter whose shared
            // chapter-list cache may have expired. Without a list there is no
            // next/prev, and the reader wrongly claims the series ended.
            // Hydrate it asynchronously and re-derive the neighbours.
            this.loadChaptersIfEmpty();
            this.saveHistory();
            this.preloadUpcomingPages();
            // Silently prefetch next chapter in background for 0-delay instant reading
            setTimeout(() => {
                this.prefetchAdjacentChapters();
            }, 600);
        },

        // Some cached chapter sets arrive without prev/next metadata. Derive
        // them from the embedded chapter list so tap/scroll mode can always
        // advance to the next chapter.
        fillAdjacentFromList() {
            if (!Array.isArray(this.chapters) || !this.chapters.length) return;
            let idx = this.chapters.findIndex(c => c.id === this.currentChapter.id);
            // A history entry can carry an id that no longer exists (provider
            // reshuffle), so fall back to the chapter number.
            if (idx < 0 && this.currentChapter.chapter) {
                const num = parseFloat(this.currentChapter.chapter);
                if (!isNaN(num)) {
                    idx = this.chapters.findIndex(c => parseFloat(c.chapter) === num);
                    if (idx >= 0) {
                        const match = this.chapters[idx];
                        this.currentChapter = Object.assign({}, this.currentChapter, {
                            id: match.id,
                            provider: match.provider || this.currentChapter.provider,
                            chapter: match.chapter || this.currentChapter.chapter,
                        });
                    }
                }
            }
            if (idx < 0) return;
            // Descending list: index-1 is the newer chapter.
            if (!this.nextChapter && idx > 0) this.nextChapter = this.chapters[idx - 1];
            if (!this.prevChapter && idx < this.chapters.length - 1) this.prevChapter = this.chapters[idx + 1];
        },

        // Fetch the chapter list when the server rendered the reader without
        // one (cold/expired cache, direct link from history). Retries a few
        // times because the first aggregation may come back partial/empty.
        async loadChaptersIfEmpty() {
            if (this.chapters.length || this.chaptersLoading) {
                if (this.chapters.length) this.autoContinueWhenLoaded = false;
                return;
            }
            this.chaptersLoading = true;
            try {
                const res = await fetch(this.chaptersUrl, { headers: { Accept: 'application/json' } });
                if (res.ok) {
                    const data = await res.json();
                    const wanted = this.currentChapter.language || null;
                    let list = Array.isArray(data.chapters) ? data.chapters : [];
                    if (wanted) {
                        const sameLang = list.filter(c => (c.language || 'en') === wanted);
                        if (sameLang.length) list = sameLang;
                    }
                    if (list.length) {
                        this.chapters = list;
                        const latest = parseFloat(list[0].chapter);
                        if (!isNaN(latest)) this.latestChapNum = latest;
                        this.fillAdjacentFromList();
                        // The visitor explicitly tried to continue before the
                        // list was known: follow through now that it is.
                        if (this.autoContinueWhenLoaded && this.nextChapter) {
                            this.autoContinueWhenLoaded = false;
                            this.goToNextChapter();
                            return;
                        }
                        this.autoContinueWhenLoaded = false;
                        setTimeout(() => this.prefetchAdjacentChapters(), 400);
                    }
                }
            } catch (e) {}
            this.chaptersLoading = false;
            // Still nothing? Try again shortly (partial aggregation).
            if (!this.chapters.length && this.chaptersTries < 4) {
                this.chaptersTries++;
                setTimeout(() => this.loadChaptersIfEmpty(), 5000);
            }
        },

        saveHistory() {
            try {
                let hist = JSON.parse(localStorage.getItem('bibliomanga_history') || '[]');
                hist = hist.filter(h => h.id !== this.mangaId);
                hist.unshift({
                    id: this.mangaId,
                    title: this.mangaTitle,
                    coverUrl: this.mangaCoverUrl,
                    chapterId: this.currentChapter.id,
                    chapterNumber: this.currentChapter.chapter,
                    chapterTitle: this.currentChapter.title || '',
                    source: this.currentChapter.provider || 'MangaDex',
                    language: this.currentChapter.language || 'id',
                    readPath: this.currentChapter.readPath || null,
                    readAt: new Date().toLocaleDateString('en-US', { day: 'numeric', month: 'short' }) + ', ' + new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
                });
                if (hist.length > 50) hist = hist.slice(0, 50);
                localStorage.setItem('bibliomanga_history', JSON.stringify(hist));
            } catch(e) {}
        },

        buildReadUrl(chap) {
            if (!chap) return null;
            let url = '/manga/' + this.mangaId + '/chapter/' + chap.id + '?ch=' + encodeURIComponent(chap.chapter || '0') + '&source=' + encodeURIComponent(chap.provider || 'MangaDex');
            if (chap.readPath) {
                try {
                    url += '&p=' + encodeURIComponent(btoa(chap.readPath));
                } catch(e) {}
            }
            return url;
        },

        showToast(msg) {
            // Disabled: toast notifications removed per user request
        },

        toggleHandMode() {
            this.handMode = (this.handMode === 'right') ? 'left' : 'right';
            localStorage.setItem('reader_hand_mode', this.handMode);
        },

        toggleMode() {
            this.mode = (this.mode === 'paged') ? 'webtoon' : 'paged';
            localStorage.setItem('reader_mode', this.mode);
        },

        setMode(newMode) {
            this.mode = newMode;
            localStorage.setItem('reader_mode', newMode);
        },

        toggleFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().then(() => {
                    this.isFullscreen = true;
                }).catch(() => {});
            } else {
                document.exitFullscreen().then(() => {
                    this.isFullscreen = false;
                }).catch(() => {});
            }
        },

        scrollToTop() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        },

        updateProgress() {
            const h = document.documentElement,
                  b = document.body,
                  st = 'scrollTop',
                  sh = 'scrollHeight';
            const top = (h[st] || b[st]) || 0;
            const height = (h[sh] || b[sh]) || 0;
            this.progress = Math.min(100, Math.round((top / (height - h.clientHeight)) * 100)) || 0;

            // Webtoon mode: reaching the bottom means the chapter is done.
            // Arm only after the reader has scrolled a little, then require
            // the bottom to stay visible for a moment so a fast fling does
            // not skip the final panels.
            if (this.mode !== 'webtoon' || this.autoNextDone) {
                this.bottomSince = 0;
                return;
            }
            const nearBottom = top + h.clientHeight >= height - 80;
            if (!this.nextChapter) {
                // No neighbour known yet (cold chapter-list cache). Keep the
                // reader from claiming the series ended; load the list and
                // continue once it is known.
                this.bottomSince = 0;
                if (nearBottom && !this.chaptersLoading) {
                    this.autoContinueWhenLoaded = true;
                    this.loadChaptersIfEmpty();
                }
                return;
            }
            if (!nearBottom) {
                this.bottomSince = 0;
                if (this.progress > 5) this.autoNextArmed = true;
                return;
            }
            if (!this.autoNextArmed) return;
            if (!this.bottomSince) {
                this.bottomSince = Date.now();
                return;
            }
            if (Date.now() - this.bottomSince >= 700) {
                this.autoNextDone = true;
                this.goToNextChapter();
            }
        },

        // Background Preload next 2-3 images for zero-delay page turning
        preloadUpcomingPages() {
            if (!this.pages || this.pages.length === 0) return;
            for (let i = 1; i <= 3; i++) {
                const targetIdx = this.currentPage - 1 + i;
                if (this.pages[targetIdx]) {
                    const img = new Image();
                    img.src = this.pages[targetIdx].proxyUrl;
                }
            }
        },

        // Background Prefetch adjacent chapter pages
        async prefetchAdjacentChapters() {
            if (this.nextChapter && !this.prefetchedNextChapter) {
                try {
                    let url = '/manga/' + this.mangaId + '/chapter/' + this.nextChapter.id + '/pages-json?ch=' + encodeURIComponent(this.nextChapter.chapter || '0') + '&source=' + encodeURIComponent(this.nextChapter.provider || 'MangaDex');
                    if (this.nextChapter.readPath) {
                        try {
                            url += '&p=' + encodeURIComponent(btoa(this.nextChapter.readPath));
                        } catch(e) {}
                    }
                    const res = await fetch(url);
                    if (res.ok) {
                        const data = await res.json();
                        if (data && data.pages && data.pages.length > 0) {
                            this.prefetchedNextChapter = data;
                            // Preload first 3 images into cache
                            data.pages.slice(0, 3).forEach(pg => {
                                const img = new Image();
                                img.src = pg.proxyUrl;
                            });
                        }
                    }
                } catch(e) {}
            }
        },

        // ZERO DELAY Chapter Transition (In-place state swap without page reload)
        applyChapterData(data) {
            this.pages = data.pages;
            this.totalPages = data.pages.length;
            this.currentPage = 1;
            this.currentChapter = data.currentChapter;
            this.nextChapter = data.nextChapter;
            this.prevChapter = data.prevChapter;
            this.fillAdjacentFromList();
            if (data.latestChapNum) {
                this.latestChapNum = data.latestChapNum;
            }
            this.prefetchedNextChapter = null;
            this.showChapterList = false;
            // Re-arm the end-of-chapter trigger for the new chapter.
            this.autoNextArmed = false;
            this.autoNextDone = false;
            this.bottomSince = 0;

            // Push state URL
            const newUrl = this.buildReadUrl(this.currentChapter);
            if (newUrl) {
                window.history.pushState(null, '', newUrl);
            }
            document.title = 'Ch. ' + this.currentChapter.chapter + ' — ' + this.mangaTitle + ' | BiblioManga';

            this.saveHistory();
            window.scrollTo({ top: 0, behavior: 'instant' });

            // Preload next chapter and pages for ongoing zero-delay
            this.preloadUpcomingPages();
            setTimeout(() => {
                this.prefetchAdjacentChapters();
            }, 600);
        },

        async goToNextChapter() {
            if (!this.nextChapter) {
                return;
            }

            // If already prefetched in RAM: INSTANT 0ms ZERO DELAY SWAP!
            if (this.prefetchedNextChapter && this.prefetchedNextChapter.pages && this.prefetchedNextChapter.pages.length > 0) {
                this.applyChapterData(this.prefetchedNextChapter);
                return;
            }

            // In-place fast fetch
            try {
                let url = '/manga/' + this.mangaId + '/chapter/' + this.nextChapter.id + '/pages-json?ch=' + encodeURIComponent(this.nextChapter.chapter || '0') + '&source=' + encodeURIComponent(this.nextChapter.provider || 'MangaDex');
                if (this.nextChapter.readPath) {
                    try {
                        url += '&p=' + encodeURIComponent(btoa(this.nextChapter.readPath));
                    } catch(e) {}
                }
                const res = await fetch(url);
                if (res.ok) {
                    const data = await res.json();
                    this.applyChapterData(data);
                    return;
                }
            } catch(e) {}

            window.location.href = this.buildReadUrl(this.nextChapter);
        },

        async goToPrevChapter() {
            if (!this.prevChapter) {
                return;
            }
            try {
                let url = '/manga/' + this.mangaId + '/chapter/' + this.prevChapter.id + '/pages-json?ch=' + encodeURIComponent(this.prevChapter.chapter || '0') + '&source=' + encodeURIComponent(this.prevChapter.provider || 'MangaDex');
                if (this.prevChapter.readPath) {
                    try {
                        url += '&p=' + encodeURIComponent(btoa(this.prevChapter.readPath));
                    } catch(e) {}
                }
                const res = await fetch(url);
                if (res.ok) {
                    const data = await res.json();
                    this.applyChapterData(data);
                    return;
                }
            } catch(e) {}

            window.location.href = this.buildReadUrl(this.prevChapter);
        },

        // Jump to any chapter from the in-reader chapter list. Uses the same
        // zero-delay in-place swap as next/prev when the pages endpoint
        // responds, otherwise falls back to a normal navigation.
        async goToChapter(chap) {
            if (!chap) return;
            if (chap.id === this.currentChapter.id) {
                this.showChapterList = false;
                return;
            }
            if (this.nextChapter && chap.id === this.nextChapter.id && this.prefetchedNextChapter) {
                this.applyChapterData(this.prefetchedNextChapter);
                return;
            }
            try {
                let url = '/manga/' + this.mangaId + '/chapter/' + chap.id + '/pages-json?ch=' + encodeURIComponent(chap.chapter || '0') + '&source=' + encodeURIComponent(chap.provider || 'MangaDex');
                if (chap.readPath) {
                    try {
                        url += '&p=' + encodeURIComponent(btoa(chap.readPath));
                    } catch(e) {}
                }
                const res = await fetch(url);
                if (res.ok) {
                    const data = await res.json();
                    this.applyChapterData(data);
                    return;
                }
            } catch(e) {}
            window.location.href = this.buildReadUrl(chap);
        },

        nextPage() {
            if (this.currentPage < this.totalPages) {
                this.currentPage++;
                window.scrollTo({ top: 0, behavior: 'smooth' });
                this.preloadUpcomingPages();
            } else if (this.nextChapter) {
                this.goToNextChapter();
            } else if (!this.chapters.length) {
                // Chapter list still unknown (opened from history on a cold
                // cache). Load it, then continue automatically.
                this.autoContinueWhenLoaded = true;
                this.loadChaptersIfEmpty();
            }
        },

        prevPage() {
            if (this.currentPage > 1) {
                this.currentPage--;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else if (this.prevChapter) {
                this.goToPrevChapter();
            }
        }
      }"
      @fullscreenchange.window="isFullscreen = !!document.fullscreenElement"
      @scroll.window="updateProgress()"
      @keydown.right.window="if(mode === 'paged') nextPage()"
      @keydown.left.window="if(mode === 'paged') prevPage()"
      @keydown.escape.window="showMenu = false"
      class="min-h-screen bg-[#08090a] selection:bg-[#0f766e] selection:text-white antialiased">

    <!-- Thin Reading Progress Bar at the very top -->
    <div class="fixed top-0 left-0 right-0 z-40 h-1 bg-white/10 pointer-events-none">
        <div class="h-full bg-gradient-to-r from-[#0f766e] to-[#f97316] transition-all duration-150"
             :style="'width: ' + (mode === 'webtoon' ? progress : ((currentPage / totalPages) * 100)) + '%'"></div>
    </div>

    <!-- ==============================================
         OVERLAY CONTROLS (Muncul saat tekan tengah layar)
         ============================================== -->

    <!-- 1. Top Chapter Pill: < Ch. X / Y > (Instant Zero Delay Action) -->
    <div x-show="showMenu"
         x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-4"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-4"
         class="fixed top-4 sm:top-6 left-1/2 -translate-x-1/2 z-50 flex items-center gap-3 rounded-2xl bg-[#14171d]/95 backdrop-blur-md border border-white/10 px-4 py-2.5 shadow-2xl">
        
        <!-- Prev Chapter Button -->
        <button type="button"
                @click.stop="goToPrevChapter()"
                :disabled="!prevChapter"
                class="flex h-8 w-8 items-center justify-center rounded-xl transition"
                :class="prevChapter ? 'bg-white/5 text-white/80 hover:bg-white/15 hover:text-white cursor-pointer' : 'text-white/20 cursor-not-allowed'"
                :title="prevChapter ? ('Previous Chapter (Ch. ' + prevChapter.chapter + ')') : 'No previous chapter'">
            <span class="material-symbols-rounded text-lg">chevron_left</span>
        </button>

        <!-- Chapter Display -->
        <div class="px-2 select-none text-center">
            <span class="text-xs sm:text-sm font-bold text-white tracking-wide" style="font-family: 'Space Grotesk', sans-serif;">
                Ch. <span x-text="currentChapter.chapter"></span>
            </span>
            <span class="text-xs sm:text-sm text-white/40 font-medium">
                / <span x-text="latestChapNum"></span>
            </span>
        </div>

        <!-- Next Chapter Button -->
        <button type="button"
                @click.stop="goToNextChapter()"
                :disabled="!nextChapter"
                class="flex h-8 w-8 items-center justify-center rounded-xl transition"
                :class="nextChapter ? 'bg-white/5 text-white/80 hover:bg-white/15 hover:text-white cursor-pointer' : 'text-white/20 cursor-not-allowed'"
                :title="nextChapter ? ('Next Chapter (Ch. ' + nextChapter.chapter + ')') : 'No next chapter'">
            <span class="material-symbols-rounded text-lg">chevron_right</span>
        </button>

    </div>

    <!-- 2. Right Floating Vertical Action Bar -->
    <div x-show="showMenu"
         x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 translate-x-4"
         x-transition:enter-end="opacity-100 translate-x-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-x-0"
         x-transition:leave-end="opacity-0 translate-x-4"
         class="fixed right-3 sm:right-6 top-1/2 -translate-y-1/2 z-50 flex flex-col items-center rounded-2xl bg-[#14171d]/95 backdrop-blur-md border border-white/10 p-2 gap-3 shadow-2xl">
        
        <!-- Tombol Back to Home -->
        <a href="{{ route('manga.index') }}"
           @click.stop
           class="flex h-10 w-10 items-center justify-center rounded-xl text-white/80 hover:bg-white/15 hover:text-white transition"
           title="Back to Home">
            <span class="material-symbols-rounded text-2xl">home</span>
        </a>

        <!-- Tombol Reading Mode Toggle (Bentuk Buku Terbuka: Slide <=> Webtoon) -->
        <button type="button"
                @click.stop="toggleMode()"
                :title="mode === 'paged' ? 'Mode: Slide (Click to switch to Webtoon)' : 'Mode: Webtoon (Click to switch to Slide)'"
                class="flex h-10 w-10 items-center justify-center rounded-xl transition hover:bg-white/15 relative"
                :class="mode === 'paged' ? 'text-[#2dd4bf] bg-white/10' : 'text-white/80 hover:text-white'">
            <span class="material-symbols-rounded text-2xl">menu_book</span>
            <span class="absolute bottom-1 right-1 h-1.5 w-1.5 rounded-full"
                  :class="mode === 'paged' ? 'bg-[#2dd4bf]' : 'bg-amber-400'"></span>
        </button>

        <!-- Tombol Hand Mode (Mode Tangan Kiri / Kanan khusus Mode Tap / Slide) -->
        <button type="button"
                x-show="mode === 'paged'"
                @click.stop="toggleHandMode()"
                :title="handMode === 'left' ? 'Left-Hand Mode Active (Tap left for Next)' : 'Right-Hand Mode Active (Tap right for Next)'"
                class="flex h-10 w-10 items-center justify-center rounded-xl transition hover:bg-white/15 relative"
                :class="handMode === 'left' ? 'text-amber-400 bg-white/10 ring-1 ring-amber-400/40' : 'text-white/80 hover:text-white'">
            <span class="material-symbols-rounded text-2xl">pan_tool_alt</span>
            <span class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full text-[9px] font-black uppercase text-white shadow"
                  :class="handMode === 'left' ? 'bg-amber-500' : 'bg-gray-600'">
                <span x-text="handMode === 'left' ? 'L' : 'R'"></span>
            </span>
        </button>

        <!-- Tombol Chapter List (in-reader, tanpa pindah halaman) -->
        <button type="button"
                @click.stop="showChapterList = true"
                :title="'Chapter List (' + chapters.length + ')'"
                class="flex h-10 w-10 items-center justify-center rounded-xl text-white/80 hover:bg-white/15 hover:text-white transition">
            <span class="material-symbols-rounded text-2xl">toc</span>
        </button>

        <!-- Tombol Kembali ke Detail Manga / Chapter List -->
        <a href="{{ route('manga.show', $manga['id']) }}"
           @click.stop
           class="flex h-10 w-10 items-center justify-center rounded-xl text-white/80 hover:bg-white/15 hover:text-white transition"
           title="Manga Details & Chapter List">
            <span class="material-symbols-rounded text-2xl">format_list_bulleted</span>
        </a>

        <!-- Tombol Fullscreen -->
        <button type="button"
                @click.stop="toggleFullscreen()"
                title="Fullscreen"
                class="flex h-10 w-10 items-center justify-center rounded-xl text-white/80 hover:bg-white/15 hover:text-white transition">
            <span class="material-symbols-rounded text-2xl" x-text="isFullscreen ? 'fullscreen_exit' : 'fullscreen'">fullscreen</span>
        </button>

    </div>

    <!-- 3. In-Reader Chapter List Drawer -->
    <div x-show="showChapterList" x-cloak class="fixed inset-0 z-[70]">
        <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showChapterList = false"></div>
        <aside class="absolute right-0 top-0 flex h-full w-full max-w-sm flex-col border-l border-white/10 bg-[#14171d] shadow-2xl"
               x-transition:enter="transition ease-out duration-200"
               x-transition:enter-start="translate-x-full"
               x-transition:enter-end="translate-x-0"
               x-transition:leave="transition ease-in duration-150"
               x-transition:leave-start="translate-x-0"
               x-transition:leave-end="translate-x-full">
            <div class="flex items-center justify-between gap-3 border-b border-white/10 px-4 py-3">
                <div class="min-w-0">
                    <h2 class="text-sm font-bold text-white" style="font-family: 'Space Grotesk', sans-serif;">Chapter List</h2>
                    <p class="mt-0.5 text-[11px] font-medium text-white/50">
                        Now reading Ch. <span x-text="currentChapter.chapter"></span>
                        &middot; <span x-text="chapters.length"></span> chapters
                    </p>
                </div>
                <button type="button" @click="showChapterList = false"
                        class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-xl text-white/70 transition hover:bg-white/10 hover:text-white"
                        title="Close">
                    <span class="material-symbols-rounded text-xl">close</span>
                </button>
            </div>
            <div id="readerChapterList" class="flex-1 space-y-1.5 overflow-y-auto px-3 py-3"
                 @scroll="if (chapterLimit < chapters.length && $el.scrollTop + $el.clientHeight >= $el.scrollHeight - 200) chapterLimit += 60">
                <template x-for="chap in chapters.slice(0, chapterLimit)" :key="chap.id">
                    <button type="button" @click="goToChapter(chap)"
                            class="flex w-full items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-left transition"
                            :class="chap.id === currentChapter.id ? 'bg-[#0f766e] text-white' : 'bg-white/5 text-white/80 hover:bg-white/10 hover:text-white'">
                        <span class="min-w-0">
                            <span class="block text-sm font-bold">Chapter <span x-text="chap.chapter"></span></span>
                            <span x-show="chap.title" class="block truncate text-[11px] font-medium opacity-60" x-text="chap.title"></span>
                        </span>
                        <span class="flex-shrink-0 text-[10px] font-bold uppercase tracking-wider opacity-60"
                              x-text="chap.id === currentChapter.id ? 'Reading' : (chap.publishAt || '')"></span>
                    </button>
                </template>
                <button type="button" x-show="chapterLimit < chapters.length" @click="chapterLimit += 60"
                        class="mt-2 w-full rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-xs font-bold text-[#2dd4bf] transition hover:bg-white/10">
                    Show more chapters
                </button>
            </div>
        </aside>
    </div>

    <!-- Reader Canvas -->
    <main class="min-h-screen py-0 px-0">
        
        <!-- ==============================================
             MODE 1: WEBTOON (Continuous Seamless Scroll)
             ============================================== -->
        <div x-show="mode === 'webtoon'"
             @click="showMenu = !showMenu"
             class="mx-auto transition-all duration-300 max-w-4xl cursor-pointer">

            <!-- Manga Panels Stack -->
            <div class="flex flex-col items-center">
                <template x-for="(p, idx) in pages" :key="currentChapter.id + '_' + p.page">
                    <div class="relative w-full flex flex-col items-center bg-[#14171c] overflow-hidden min-h-[350px] sm:min-h-[500px]"
                         x-data="{
                            loaded: false,
                            failed: false,
                            showError: false,
                            src: p.proxyUrl,
                            serverFallback: '/manga/proxy/image?url=' + encodeURIComponent(p.directUrl) + '&fallback=1',
                            directSrc: p.directUrl,
                            retriedServer: false,
                            handleError() {
                                if (!this.retriedServer) {
                                    this.retriedServer = true;
                                    this.src = this.serverFallback;
                                } else if (this.src !== this.directSrc) {
                                    this.src = this.directSrc;
                                } else {
                                    this.showError = true;
                                }
                            },
                            retry() {
                                this.loaded = false;
                                this.showError = false;
                                this.retriedServer = false;
                                this.src = this.serverFallback + '&retry=' + Date.now();
                            }
                         }">
                        
                        <!-- Skeleton loading placeholder -->
                        <div x-show="!loaded"
                             class="absolute inset-0 flex flex-col items-center justify-center bg-[#171a1e] text-[#6b766f] space-y-2">
                            <div class="h-8 w-8 animate-spin rounded-full border-2 border-[#0f766e] border-t-transparent"></div>
                            <span class="text-[11px] font-semibold text-white/40 tracking-wider">Page <span x-text="p.page"></span></span>
                        </div>

                        <!-- Panel Image -->
                        <img :src="src"
                             :alt="'Page ' + p.page"
                             loading="lazy"
                             referrerpolicy="no-referrer"
                             x-on:load="loaded = true"
                             x-on:error="handleError()"
                             class="w-full h-auto block select-none pointer-events-none transition-opacity duration-300 shadow-sm"
                             :class="loaded ? 'opacity-100' : 'opacity-0'" />

                        <!-- Error fallback box -->
                        <div x-show="showError && !loaded"
                             class="absolute inset-0 flex flex-col items-center justify-center p-6 bg-[#1a1315] text-center space-y-2">
                            <span class="material-symbols-rounded text-2xl text-amber-500">broken_image</span>
                            <p class="text-xs text-white/80">Page <span x-text="p.page"></span> failed to load.</p>
                            <button type="button" @click.stop="retry()"
                                    class="rounded-full bg-[#0f766e] px-4 py-1.5 text-xs font-bold text-white transition hover:bg-[#115e59]">
                                Retry
                            </button>
                        </div>

                    </div>
                </template>
            </div>

            {{-- End of chapter: explicit next-chapter action in scroll mode --}}
            <div class="w-full px-4 pb-12 pt-6 text-center">
                <button type="button" x-show="nextChapter" @click.stop="goToNextChapter()"
                        class="inline-flex items-center gap-2 rounded-full bg-[#0f766e] px-6 py-3 text-sm font-bold text-white shadow-lg transition hover:bg-[#115e59]">
                    Next Chapter
                    <span class="text-white/70">Ch. <span x-text="nextChapter ? nextChapter.chapter : ''"></span></span>
                    <span class="material-symbols-rounded text-lg">arrow_forward</span>
                </button>
                <p x-show="!nextChapter && chapters.length > 0" class="text-sm font-semibold text-white/50">
                    You have reached the latest chapter.
                </p>
                <p x-show="!nextChapter && chapters.length === 0" class="text-sm font-semibold text-white/50">
                    Memuat daftar chapter…
                </p>
                <p x-show="nextChapter" class="mt-2 text-[11px] font-medium text-white/35">
                    Keep scrolling to continue automatically.
                </p>
            </div>

        </div>

        <!-- ==============================================
             MODE 2: SLIDE / PAGED (Pure Image Focus - Borderless)
             ============================================== -->
        <div x-show="mode === 'paged'" class="relative min-h-screen flex flex-col items-center justify-center w-full select-none">
            
            <!-- Click Areas (Responsive Hand Mode):
                 Default (Right Hand): Left 30% Prev, Mid 40% Menu, Right 30% Next
                 Left Hand Mode: Left 30% Next, Mid 40% Menu, Right 30% Prev -->
            <div class="fixed inset-y-0 left-0 w-[30%] z-20 cursor-default"
                 @click="handMode === 'left' ? nextPage() : prevPage()"
                 :title="handMode === 'left' ? 'Next Page (Left-Hand Mode)' : 'Previous Page (Click / Left Arrow)'"></div>
            
            <div class="fixed inset-y-0 left-[30%] w-[40%] z-20 cursor-pointer"
                 @click="showMenu = !showMenu"
                 title="Navigation Menu (Tap Center)"></div>

            <div class="fixed inset-y-0 right-0 w-[30%] z-20 cursor-default"
                 @click="handMode === 'left' ? prevPage() : nextPage()"
                 :title="handMode === 'left' ? 'Previous Page (Left-Hand Mode)' : 'Next Page (Click / Right Arrow)'"></div>

            <!-- The Pure Manga Image -->
            <div class="relative w-full flex items-center justify-center min-h-screen">
                <template x-for="(p, idx) in pages" :key="currentChapter.id + '_' + p.page">
                    <div x-show="currentPage === p.page"
                         class="relative flex items-center justify-center max-w-full"
                         x-data="{
                            pLoaded: false,
                            pFailed: false,
                            pShowError: false,
                            pSrc: p.proxyUrl,
                            pServerFallback: '/manga/proxy/image?url=' + encodeURIComponent(p.directUrl) + '&fallback=1',
                            pDirectSrc: p.directUrl,
                            pRetriedServer: false,
                            handleError() {
                                if (!this.pRetriedServer) {
                                    this.pRetriedServer = true;
                                    this.pSrc = this.pServerFallback;
                                } else if (this.pSrc !== this.pDirectSrc) {
                                    this.pSrc = this.pDirectSrc;
                                } else {
                                    this.pShowError = true;
                                }
                            },
                            retry() {
                                this.pLoaded = false;
                                this.pShowError = false;
                                this.pRetriedServer = false;
                                this.pSrc = this.pServerFallback + '&retry=' + Date.now();
                            }
                         }">
                        
                        <!-- Skeleton loading -->
                        <div x-show="!pLoaded" class="min-h-[70vh] flex flex-col items-center justify-center text-white/40 space-y-3">
                            <div class="h-8 w-8 animate-spin rounded-full border-2 border-[#0f766e] border-t-transparent"></div>
                            <span class="text-xs font-semibold text-white/50 tracking-wider">Page <span x-text="p.page"></span></span>
                        </div>

                        <!-- Pure Image -->
                        <img :src="pSrc"
                             :alt="'Page ' + p.page"
                             referrerpolicy="no-referrer"
                             x-on:load="pLoaded = true; pShowError = false"
                             x-on:error="handleError()"
                             class="max-h-screen w-auto max-w-full object-contain block transition-opacity duration-200 select-none shadow-2xl pointer-events-none"
                             :class="pLoaded ? 'opacity-100' : 'opacity-0'" />

                        <!-- Error fallback in Slide mode -->
                        <div x-show="pShowError && !pLoaded"
                             class="min-h-[60vh] flex flex-col items-center justify-center p-6 text-center space-y-3 z-30">
                            <span class="material-symbols-rounded text-3xl text-amber-500">broken_image</span>
                            <p class="text-xs text-white/80">Page <span x-text="p.page"></span> failed to load.</p>
                            <button type="button" @click.stop="retry()"
                                    class="rounded-full bg-[#0f766e] px-4 py-1.5 text-xs font-bold text-white transition hover:bg-[#115e59]">
                                Retry
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            {{-- End of chapter: tap-friendly next-chapter action on the last page --}}
            <div class="fixed bottom-16 left-1/2 z-30 -translate-x-1/2 text-center" x-show="currentPage >= totalPages" x-cloak>
                <button type="button" x-show="nextChapter" @click.stop="goToNextChapter()"
                        class="inline-flex items-center gap-2 rounded-full bg-[#0f766e] px-5 py-2.5 text-sm font-bold text-white shadow-xl transition hover:bg-[#115e59]">
                    Next Chapter
                    <span class="text-white/70">Ch. <span x-text="nextChapter ? nextChapter.chapter : ''"></span></span>
                    <span class="material-symbols-rounded text-lg">arrow_forward</span>
                </button>
                <p x-show="!nextChapter && chapters.length > 0" class="rounded-full bg-black/70 px-4 py-2 text-xs font-semibold text-white/70">
                    You have reached the latest chapter.
                </p>
                <p x-show="!nextChapter && chapters.length === 0" class="rounded-full bg-black/70 px-4 py-2 text-xs font-semibold text-white/70">
                    Memuat daftar chapter…
                </p>
            </div>
        </div>

    </main>

    <!-- Floating Scroll-To-Top Button (Hanya di mode Webtoon) -->
    <button type="button" @click.stop="scrollToTop()"
            x-show="mode === 'webtoon' && progress > 15"
            x-transition
            class="fixed bottom-6 right-6 z-40 flex h-11 w-11 items-center justify-center rounded-2xl bg-[#0f766e] text-white shadow-xl shadow-[#0f766e]/40 transition hover:scale-110 hover:bg-[#115e59]"
            title="Scroll to Top">
        <span class="material-symbols-rounded text-xl">arrow_upward</span>
    </button>

    @include('partials.animated-favicon')
</body>
</html>
