<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ch. {{ $currentChapter['chapter'] }} — {{ $manga['title'] }} | BiblioManga</title>

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
        latestChapNum: {{ !empty($sameLangChapters[0]['chapter']) ? (int)$sameLangChapters[0]['chapter'] : (count($sameLangChapters) ?: 1) }},
        prefetchedNextChapter: null,
        prefetchedPrevChapter: null,
        progress: 0,
        showMenu: false,
        isFullscreen: false,
        toastOpen: false,
        toastMsg: '',
        
        init() {
            this.totalPages = this.pages.length;
            this.saveHistory();
            this.preloadUpcomingPages();
            // Silently prefetch next chapter in background for 0-delay instant reading
            setTimeout(() => {
                this.prefetchAdjacentChapters();
            }, 600);
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
                    readAt: new Date().toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }) + ', ' + new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
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
            this.progress = Math.min(100, Math.round(((h[st]||b[st]) / ((h[sh]||b[sh]) - h.clientHeight)) * 100)) || 0;
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
            if (data.latestChapNum) {
                this.latestChapNum = data.latestChapNum;
            }
            this.prefetchedNextChapter = null;

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

        nextPage() {
            if (this.currentPage < this.totalPages) {
                this.currentPage++;
                window.scrollTo({ top: 0, behavior: 'smooth' });
                this.preloadUpcomingPages();
            } else if (this.nextChapter) {
                this.goToNextChapter();
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
                :title="prevChapter ? ('Chapter Sebelumnya (Ch. ' + prevChapter.chapter + ')') : 'Tidak ada chapter sebelumnya'">
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
                :title="nextChapter ? ('Chapter Selanjutnya (Ch. ' + nextChapter.chapter + ')') : 'Tidak ada chapter selanjutnya'">
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
           title="Kembali ke Beranda">
            <span class="material-symbols-rounded text-2xl">home</span>
        </a>

        <!-- Tombol Reading Mode Toggle (Bentuk Buku Terbuka: Slide <=> Webtoon) -->
        <button type="button"
                @click.stop="toggleMode()"
                :title="mode === 'paged' ? 'Mode: Slide (Klik untuk ganti ke Webtoon)' : 'Mode: Webtoon (Klik untuk ganti ke Slide)'"
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
                :title="handMode === 'left' ? 'Mode Tangan Kiri Aktif (Ketuk kiri untuk Next)' : 'Mode Tangan Kanan Aktif (Ketuk kanan untuk Next)'"
                class="flex h-10 w-10 items-center justify-center rounded-xl transition hover:bg-white/15 relative"
                :class="handMode === 'left' ? 'text-amber-400 bg-white/10 ring-1 ring-amber-400/40' : 'text-white/80 hover:text-white'">
            <span class="material-symbols-rounded text-2xl">pan_tool_alt</span>
            <span class="absolute -top-1 -right-1 flex h-4 w-4 items-center justify-center rounded-full text-[9px] font-black uppercase text-white shadow"
                  :class="handMode === 'left' ? 'bg-amber-500' : 'bg-gray-600'">
                <span x-text="handMode === 'left' ? 'L' : 'R'"></span>
            </span>
        </button>

        <!-- Tombol Kembali ke Detail Manga / Chapter List -->
        <a href="{{ route('manga.show', $manga['id']) }}"
           @click.stop
           class="flex h-10 w-10 items-center justify-center rounded-xl text-white/80 hover:bg-white/15 hover:text-white transition"
           title="Detail Manga & Daftar Chapter">
            <span class="material-symbols-rounded text-2xl">format_list_bulleted</span>
        </a>

        <!-- Tombol Fullscreen -->
        <button type="button"
                @click.stop="toggleFullscreen()"
                title="Layar Penuh"
                class="flex h-10 w-10 items-center justify-center rounded-xl text-white/80 hover:bg-white/15 hover:text-white transition">
            <span class="material-symbols-rounded text-2xl" x-text="isFullscreen ? 'fullscreen_exit' : 'fullscreen'">fullscreen</span>
        </button>

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
                            <span class="text-[11px] font-semibold text-white/40 tracking-wider">Halaman <span x-text="p.page"></span></span>
                        </div>

                        <!-- Panel Image -->
                        <img :src="src"
                             :alt="'Halaman ' + p.page"
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
                            <p class="text-xs text-white/80">Halaman <span x-text="p.page"></span> terhambat koneksi.</p>
                            <button type="button" @click.stop="retry()"
                                    class="rounded-full bg-[#0f766e] px-4 py-1.5 text-xs font-bold text-white transition hover:bg-[#115e59]">
                                Coba Muat Ulang
                            </button>
                        </div>

                    </div>
                </template>
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
                 :title="handMode === 'left' ? 'Halaman Berikutnya (Mode Tangan Kiri)' : 'Halaman Sebelumnya (Klik / Panah Kiri)'"></div>
            
            <div class="fixed inset-y-0 left-[30%] w-[40%] z-20 cursor-pointer"
                 @click="showMenu = !showMenu"
                 title="Menu Navigasi (Ketuk Tengah Layar)"></div>

            <div class="fixed inset-y-0 right-0 w-[30%] z-20 cursor-default"
                 @click="handMode === 'left' ? prevPage() : nextPage()"
                 :title="handMode === 'left' ? 'Halaman Sebelumnya (Mode Tangan Kiri)' : 'Halaman Berikutnya (Klik / Panah Kanan)'"></div>

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
                            <span class="text-xs font-semibold text-white/50 tracking-wider">Halaman <span x-text="p.page"></span></span>
                        </div>

                        <!-- Pure Image -->
                        <img :src="pSrc"
                             :alt="'Halaman ' + p.page"
                             referrerpolicy="no-referrer"
                             x-on:load="pLoaded = true; pShowError = false"
                             x-on:error="handleError()"
                             class="max-h-screen w-auto max-w-full object-contain block transition-opacity duration-200 select-none shadow-2xl pointer-events-none"
                             :class="pLoaded ? 'opacity-100' : 'opacity-0'" />

                        <!-- Error fallback in Slide mode -->
                        <div x-show="pShowError && !pLoaded"
                             class="min-h-[60vh] flex flex-col items-center justify-center p-6 text-center space-y-3 z-30">
                            <span class="material-symbols-rounded text-3xl text-amber-500">broken_image</span>
                            <p class="text-xs text-white/80">Halaman <span x-text="p.page"></span> terhambat koneksi.</p>
                            <button type="button" @click.stop="retry()"
                                    class="rounded-full bg-[#0f766e] px-4 py-1.5 text-xs font-bold text-white transition hover:bg-[#115e59]">
                                Coba Muat Ulang
                            </button>
                        </div>
                    </div>
                </template>
            </div>
        </div>

    </main>

    <!-- Floating Scroll-To-Top Button (Hanya di mode Webtoon) -->
    <button type="button" @click.stop="scrollToTop()"
            x-show="mode === 'webtoon' && progress > 15"
            x-transition
            class="fixed bottom-6 right-6 z-40 flex h-11 w-11 items-center justify-center rounded-2xl bg-[#0f766e] text-white shadow-xl shadow-[#0f766e]/40 transition hover:scale-110 hover:bg-[#115e59]"
            title="Kembali ke Atas">
        <span class="material-symbols-rounded text-xl">arrow_upward</span>
    </button>

</body>
</html>
