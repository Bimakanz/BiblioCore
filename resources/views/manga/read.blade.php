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
        
        /* Smooth fade in for manga images */
        .manga-panel {
            transition: opacity 0.3s ease-in-out;
        }
        .manga-panel.loading {
            opacity: 0;
        }
        .manga-panel.loaded {
            opacity: 1;
        }
    </style>
</head>
<body x-data="{
        mode: localStorage.getItem('reader_mode') || 'webtoon',
        widthMode: localStorage.getItem('reader_width') || 'normal', // 'compact', 'normal', 'full'
        quality: localStorage.getItem('reader_quality') || 'hd', // 'hd' or 'saver'
        currentPage: 1,
        totalPages: {{ $totalPages }},
        progress: 0,
        showTopBar: true,
        lastScrollTop: 0,
        nextChapterUrl: @js($nextChapter ? route('manga.read', ['id' => $manga['id'], 'chapterId' => $nextChapter['id']]) : null),
        prevChapterUrl: @js($prevChapter ? route('manga.read', ['id' => $manga['id'], 'chapterId' => $prevChapter['id']]) : null),
        
        init() {
            try {
                let hist = JSON.parse(localStorage.getItem('bibliomanga_history') || '[]');
                hist = hist.filter(h => h.id !== '{{ $manga['id'] }}');
                hist.unshift({
                    id: '{{ $manga['id'] }}',
                    title: @js($manga['title']),
                    coverUrl: @js($manga['coverUrl']),
                    chapterId: '{{ $currentChapter['id'] }}',
                    chapterNumber: '{{ $currentChapter['chapter'] }}',
                    chapterTitle: @js($currentChapter['title'] ?? ''),
                    readAt: new Date().toLocaleDateString('id-ID', { day: 'numeric', month: 'short' }) + ', ' + new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' })
                });
                if (hist.length > 50) hist = hist.slice(0, 50);
                localStorage.setItem('bibliomanga_history', JSON.stringify(hist));
            } catch(e) {}
        },
        
        setMode(newMode) {
            this.mode = newMode;
            localStorage.setItem('reader_mode', newMode);
        },
        setWidth(w) {
            this.widthMode = w;
            localStorage.setItem('reader_width', w);
        },
        toggleQuality() {
            this.quality = (this.quality === 'hd') ? 'saver' : 'hd';
            localStorage.setItem('reader_quality', this.quality);
            window.location.reload();
        },
        nextPage() {
            if (this.currentPage < this.totalPages) {
                this.currentPage++;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else if (this.nextChapterUrl) {
                // Di panel manga terakhir: otomatis lompat ke chapter selanjutnya!
                window.location.href = this.nextChapterUrl;
            }
        },
        prevPage() {
            if (this.currentPage > 1) {
                this.currentPage--;
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else if (this.prevChapterUrl) {
                window.location.href = this.prevChapterUrl;
            }
        },
        toggleFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(() => {});
            } else {
                document.exitFullscreen().catch(() => {});
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
        }
      }"
      @scroll.window="updateProgress()"
      @keydown.right.window="if(mode === 'paged') nextPage()"
      @keydown.left.window="if(mode === 'paged') prevPage()"
      class="min-h-screen selection:bg-[#0f766e] selection:text-white antialiased">

    <!-- Thin Reading Progress Bar at the very top -->
    <div class="fixed top-0 left-0 right-0 z-50 h-1 bg-white/10">
        <div class="h-full bg-gradient-to-r from-[#0f766e] to-[#f97316] transition-all duration-150"
             :style="'width: ' + (mode === 'webtoon' ? progress : ((currentPage / totalPages) * 100)) + '%'"></div>
    </div>

    <!-- Sticky Navigation & Control Bar -->
    <header class="sticky top-0 z-40 border-b border-white/10 bg-[#14171c]/95 backdrop-blur-md px-3 py-2.5 sm:px-6 transition-all duration-200">
        <div class="mx-auto flex max-w-7xl items-center justify-between gap-2 sm:gap-4">
            
            <!-- Left: Back button & Manga Title -->
            <div class="flex items-center gap-2.5 min-w-0">
                <a href="{{ route('manga.show', $manga['id']) }}"
                   class="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-white/10 text-white transition hover:bg-white/20 hover:scale-105"
                   title="Kembali ke Detail Manga">
                    <span class="material-symbols-rounded text-lg">arrow_back</span>
                </a>
                
                <div class="min-w-0">
                    <h1 class="text-xs sm:text-sm font-bold text-white truncate max-w-[160px] sm:max-w-xs md:max-w-md" style="font-family: 'Space Grotesk', sans-serif;">
                        {{ $manga['title'] }}
                    </h1>
                    <div class="flex items-center gap-1.5 text-[11px] text-[#2dd4bf] font-medium">
                        <span>Chapter {{ $currentChapter['chapter'] }}</span>
                        @if($currentChapter['title'])
                            <span class="text-white/30">•</span>
                            <span class="text-white/60 truncate max-w-[130px] hidden sm:inline">{{ $currentChapter['title'] }}</span>
                        @endif
                        <span class="text-white/30">•</span>
                        <span class="text-white/50 text-[10px] uppercase font-bold">{{ strtoupper($currentChapter['language']) }}</span>
                    </div>
                </div>
            </div>

            <!-- Center: Chapter Selector Dropdown -->
            <div class="hidden md:flex items-center gap-1.5">
                @if($prevChapter && empty($prevChapter['externalUrl']))
                    <a href="{{ route('manga.read', ['id' => $manga['id'], 'chapterId' => $prevChapter['id']]) }}"
                       class="rounded-lg p-1.5 bg-white/10 text-white/80 hover:bg-white/20 transition"
                       title="Chapter Sebelumnya">
                        <span class="material-symbols-rounded text-base block">chevron_left</span>
                    </a>
                @endif

                <select onchange="window.location.href=this.value"
                        class="rounded-xl border border-white/20 bg-[#1c2027] px-3 py-1.5 text-xs font-semibold text-white focus:border-[#0f766e] focus:outline-none">
                    @foreach($sameLangChapters ?? $allChapters as $chap)
                        @if(empty($chap['externalUrl']))
                            <option value="{{ route('manga.read', ['id' => $manga['id'], 'chapterId' => $chap['id']]) }}"
                                    {{ $chap['id'] === $currentChapter['id'] ? 'selected' : '' }}>
                                Ch. {{ $chap['chapter'] }} [{{ strtoupper($chap['language']) }}] {{ $chap['title'] ? ' - ' . Str::limit($chap['title'], 22) : '' }}
                            </option>
                        @endif
                    @endforeach
                </select>

                @if($nextChapter && empty($nextChapter['externalUrl']))
                    <a href="{{ route('manga.read', ['id' => $manga['id'], 'chapterId' => $nextChapter['id']]) }}"
                       class="rounded-lg p-1.5 bg-white/10 text-white/80 hover:bg-white/20 transition"
                       title="Chapter Selanjutnya">
                        <span class="material-symbols-rounded text-base block">chevron_right</span>
                    </a>
                @endif
            </div>

            <!-- Right: Controls (Reading Mode, Width, Quality, Fullscreen) -->
            <div class="flex items-center gap-1.5 sm:gap-2">
                
                <!-- Mode Switcher -->
                <div class="flex items-center rounded-xl bg-white/10 p-0.5 text-xs">
                    <button type="button" @click="setMode('webtoon')"
                            :class="mode === 'webtoon' ? 'bg-[#0f766e] text-white shadow-sm' : 'text-white/60 hover:text-white'"
                            class="rounded-lg px-2 py-1 text-[11px] font-bold transition flex items-center gap-1"
                            title="Mode Webtoon (Vertikal Scroll)">
                        <span class="material-symbols-rounded text-sm">view_stream</span>
                        <span class="hidden lg:inline">Webtoon</span>
                    </button>
                    <button type="button" @click="setMode('paged')"
                            :class="mode === 'paged' ? 'bg-[#0f766e] text-white shadow-sm' : 'text-white/60 hover:text-white'"
                            class="rounded-lg px-2 py-1 text-[11px] font-bold transition flex items-center gap-1"
                            title="Mode Slide (Halaman Tunggal)">
                        <span class="material-symbols-rounded text-sm">auto_stories</span>
                        <span class="hidden lg:inline">Slide</span>
                    </button>
                </div>

                <!-- Width Adjuster (for Webtoon mode) -->
                <div class="hidden sm:flex items-center rounded-xl bg-white/10 p-0.5 text-[10px]" x-show="mode === 'webtoon'">
                    <button type="button" @click="setWidth('compact')"
                            :class="widthMode === 'compact' ? 'bg-white/20 text-white' : 'text-white/50 hover:text-white'"
                            class="rounded-lg px-2 py-1 font-semibold transition" title="Lebar Ringkas (720px)">
                        720p
                    </button>
                    <button type="button" @click="setWidth('normal')"
                            :class="widthMode === 'normal' ? 'bg-white/20 text-white' : 'text-white/50 hover:text-white'"
                            class="rounded-lg px-2 py-1 font-semibold transition" title="Lebar Normal (960px)">
                        960p
                    </button>
                    <button type="button" @click="setWidth('full')"
                            :class="widthMode === 'full' ? 'bg-white/20 text-white' : 'text-white/50 hover:text-white'"
                            class="rounded-lg px-2 py-1 font-semibold transition" title="Layar Penuh">
                        100%
                    </button>
                </div>

                <!-- Quality Toggle Button -->
                <button type="button" @click="toggleQuality()"
                        class="rounded-xl px-2.5 py-1 text-[11px] font-bold border transition hidden sm:inline-flex items-center gap-1"
                        :class="quality === 'hd' ? 'border-[#0f766e]/40 bg-[#0f766e]/15 text-[#2dd4bf]' : 'border-amber-500/40 bg-amber-500/15 text-amber-300'"
                        :title="quality === 'hd' ? 'Kualitas HD (Asli). Klik untuk mode Hemat Kuota.' : 'Mode Hemat Kuota Aktif. Klik untuk Kualitas HD.'">
                    <span class="material-symbols-rounded text-xs" x-text="quality === 'hd' ? 'hd' : 'speed'"></span>
                    <span x-text="quality === 'hd' ? 'HD' : 'Hemat'"></span>
                </button>

                <!-- Fullscreen Toggle -->
                <button type="button" @click="toggleFullscreen()"
                        class="rounded-xl bg-white/10 p-1.5 text-white/80 hover:bg-white/20 hover:text-white transition"
                        title="Layar Penuh">
                    <span class="material-symbols-rounded text-lg block">fullscreen</span>
                </button>
            </div>

        </div>
    </header>

    <!-- Reader Canvas -->
    <main class="min-h-screen py-4 sm:py-8 px-0 sm:px-4">
        
        <!-- ==============================================
             MODE 1: WEBTOON (Continuous Seamless Scroll)
             ============================================== -->
        <div x-show="mode === 'webtoon'"
             class="mx-auto transition-all duration-300"
             :class="{
                'max-w-2xl': widthMode === 'compact',
                'max-w-4xl': widthMode === 'normal',
                'max-w-full': widthMode === 'full'
             }">

            <!-- Manga Panels Stack -->
            <div class="flex flex-col items-center">
                @foreach($pages as $p)
                    <div class="relative w-full flex flex-col items-center bg-[#14171c] overflow-hidden min-h-[350px] sm:min-h-[500px]"
                         x-data="{
                            loaded: false,
                            failed: false,
                            showError: false,
                            src: '{{ $p['directUrl'] ?? $p['url'] ?? '' }}',
                            fallbackSrc: '{{ $p['proxyUrl'] ?? route('manga.proxy.image', ['url' => $p['directUrl'] ?? $p['url'] ?? '']) }}',
                            saverSrc: '{{ $p['saverUrl'] ?? $p['directUrl'] ?? $p['url'] ?? '' }}',
                            proxySaverSrc: '{{ $p['proxySaverUrl'] ?? $p['proxyUrl'] ?? route('manga.proxy.image', ['url' => $p['directUrl'] ?? $p['url'] ?? '']) }}',
                            
                            init() {
                                if (localStorage.getItem('reader_quality') === 'saver') {
                                    this.src = this.saverSrc;
                                    this.fallbackSrc = this.proxySaverSrc;
                                }
                            },
                            handleError() {
                                if (!this.failed) {
                                    // First error: silently switch to proxy without showing error card
                                    this.failed = true;
                                    this.src = this.fallbackSrc;
                                } else {
                                    // Both direct and proxy failed: now show error
                                    this.showError = true;
                                }
                            },
                            retry() {
                                this.loaded = false;
                                this.failed = true;
                                this.showError = false;
                                this.src = this.fallbackSrc + (this.fallbackSrc.includes('?') ? '&' : '?') + 'retry=' + Date.now();
                            }
                         }">
                        
                        <!-- Skeleton loading placeholder -->
                        <div x-show="!loaded"
                             class="absolute inset-0 flex flex-col items-center justify-center bg-[#171a1e] text-[#6b766f] space-y-2">
                            <div class="h-8 w-8 animate-spin rounded-full border-2 border-[#0f766e] border-t-transparent"></div>
                            <span class="text-[11px] font-semibold text-white/40 tracking-wider">Halaman {{ $p['page'] }}</span>
                        </div>

                        <!-- Panel Image -->
                        <img :src="src"
                             alt="Halaman {{ $p['page'] }}"
                             loading="lazy"
                             referrerpolicy="no-referrer"
                             x-on:load="loaded = true"
                             x-on:error="handleError()"
                             class="w-full h-auto block select-none pointer-events-none transition-opacity duration-300 shadow-sm"
                             :class="loaded ? 'opacity-100' : 'opacity-0'" />

                        <!-- Error fallback box (hanya muncul jika direct & proxy keduanya benar-benar gagal) -->
                        <div x-show="showError && !loaded"
                             class="absolute inset-0 flex flex-col items-center justify-center p-6 bg-[#1a1315] text-center space-y-2">
                            <span class="material-symbols-rounded text-2xl text-amber-500">broken_image</span>
                            <p class="text-xs text-white/80">Halaman {{ $p['page'] }} terhambat koneksi.</p>
                            <button type="button" @click="retry()"
                                    class="rounded-full bg-[#0f766e] px-4 py-1.5 text-xs font-bold text-white transition hover:bg-[#115e59]">
                                Coba Muat Ulang
                            </button>
                        </div>

                    </div>
                @endforeach
            </div>

        </div>

        <!-- ==============================================
             MODE 2: SLIDE / PAGED (Pure Image Focus - No Box/No Border)
             ============================================== -->
        <div x-show="mode === 'paged'" class="relative min-h-[85vh] flex flex-col items-center justify-center w-full select-none py-1">
            
            <!-- Click Areas (Left 30% = Prev, Right 70% = Next) -->
            <div class="fixed inset-y-16 left-0 w-1/3 z-20 cursor-default"
                 @click="prevPage()"
                 title="Halaman Sebelumnya (Panah Kiri)"></div>
            <div class="fixed inset-y-16 right-0 w-2/3 z-20 cursor-default"
                 @click="nextPage()"
                 title="Halaman Berikutnya (Panah Kanan)"></div>

            <!-- The Pure Manga Image (Borderless, Frameless, 100% Focused) -->
            <div class="relative w-full flex items-center justify-center">
                @foreach($pages as $p)
                    <div x-show="currentPage === {{ $p['page'] }}"
                         x-cloak
                         class="relative flex items-center justify-center max-w-full"
                         x-data="{
                            pLoaded: false,
                            pFailed: false,
                            pShowError: false,
                            pSrc: '{{ $p['directUrl'] ?? $p['url'] ?? '' }}',
                            pFallbackSrc: '{{ $p['proxyUrl'] ?? route('manga.proxy.image', ['url' => $p['directUrl'] ?? $p['url'] ?? '']) }}',
                            init() {
                                if (localStorage.getItem('reader_quality') === 'saver') {
                                    this.pSrc = '{{ $p['saverUrl'] ?? $p['directUrl'] ?? $p['url'] ?? '' }}';
                                    this.pFallbackSrc = '{{ $p['proxySaverUrl'] ?? $p['proxyUrl'] ?? route('manga.proxy.image', ['url' => $p['directUrl'] ?? $p['url'] ?? '']) }}';
                                }
                            },
                            handleError() {
                                if (!this.pFailed) {
                                    this.pFailed = true;
                                    this.pSrc = this.pFallbackSrc;
                                } else {
                                    this.pShowError = true;
                                }
                            },
                            retry() {
                                this.pLoaded = false;
                                this.pFailed = true;
                                this.pShowError = false;
                                this.pSrc = this.pFallbackSrc + (this.pFallbackSrc.includes('?') ? '&' : '?') + 'retry=' + Date.now();
                            }
                         }">
                        
                        <!-- Skeleton loading -->
                        <div x-show="!pLoaded" class="min-h-[70vh] flex flex-col items-center justify-center text-white/40 space-y-3">
                            <div class="h-8 w-8 animate-spin rounded-full border-2 border-[#0f766e] border-t-transparent"></div>
                            <span class="text-xs font-semibold text-white/50 tracking-wider">Halaman {{ $p['page'] }}</span>
                        </div>

                        <!-- Pure Image -->
                        <img :src="pSrc"
                             alt="Halaman {{ $p['page'] }}"
                             referrerpolicy="no-referrer"
                             x-on:load="pLoaded = true; pShowError = false"
                             x-on:error="handleError()"
                             class="max-h-[88vh] sm:max-h-[93vh] w-auto max-w-full object-contain block transition-opacity duration-200 select-none shadow-2xl"
                             :class="pLoaded ? 'opacity-100' : 'opacity-0'" />

                        <!-- Error fallback in Slide mode -->
                        <div x-show="pShowError && !pLoaded"
                             class="min-h-[60vh] flex flex-col items-center justify-center p-6 text-center space-y-3">
                            <span class="material-symbols-rounded text-3xl text-amber-500">broken_image</span>
                            <p class="text-xs text-white/80">Halaman {{ $p['page'] }} terhambat koneksi.</p>
                            <button type="button" @click="retry()"
                                    class="rounded-full bg-[#0f766e] px-4 py-1.5 text-xs font-bold text-white transition hover:bg-[#115e59]">
                                Coba Muat Ulang
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

    </main>

    <!-- Floating Scroll-To-Top Button -->
    <button type="button" @click="scrollToTop()"
            x-show="progress > 15"
            x-transition
            class="fixed bottom-6 right-6 z-40 flex h-11 w-11 items-center justify-center rounded-2xl bg-[#0f766e] text-white shadow-xl shadow-[#0f766e]/40 transition hover:scale-110 hover:bg-[#115e59]"
            title="Kembali ke Atas">
        <span class="material-symbols-rounded text-xl">arrow_upward</span>
    </button>

</body>
</html>
