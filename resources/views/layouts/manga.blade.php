<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title ?? 'BiblioManga — Read Manga Online Without Limits' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    @if(request()->is('anime*'))
        <link rel="preconnect" href="https://s4.anilist.co" crossorigin>
        <link rel="preconnect" href="https://i.ytimg.com" crossorigin>
        <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    @endif
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&family=Material+Symbols+Rounded:wght@300;400;500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        [x-cloak] { display: none !important; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: #f1ebd8; border-radius: 9999px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #0f766e; border-radius: 9999px; }
        .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .line-clamp-3 { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }

        /* Uiverse animated delete button (Orange initially -> Red on hover/active) */
        .btn-delete-uiverse {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background-color: #ea580c;
            border: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0px 2px 10px rgba(234, 88, 12, 0.25);
            cursor: pointer;
            transition-duration: .3s;
            overflow: hidden;
            position: relative;
            flex-shrink: 0;
            outline: none;
        }

        .btn-delete-uiverse .delSvgIcon {
            width: 14px;
            height: auto;
            transition-duration: .3s;
        }

        .btn-delete-uiverse .delSvgIcon path {
            fill: white;
        }

        .btn-delete-uiverse:hover,
        .btn-delete-uiverse:focus,
        .btn-delete-uiverse:active {
            width: 140px;
            border-radius: 50px;
            transition-duration: .3s;
            background-color: rgb(255, 69, 69);
            align-items: center;
        }

        .btn-delete-uiverse:hover .delSvgIcon,
        .btn-delete-uiverse:focus .delSvgIcon,
        .btn-delete-uiverse:active .delSvgIcon {
            width: 50px;
            transition-duration: .3s;
            transform: translateY(60%);
        }

        .btn-delete-uiverse::before {
            position: absolute;
            top: -20px;
            content: "Delete";
            color: white;
            transition-duration: .3s;
            font-size: 2px;
            font-weight: 700;
        }

        .btn-delete-uiverse:hover::before,
        .btn-delete-uiverse:focus::before,
        .btn-delete-uiverse:active::before {
            font-size: 13px;
            opacity: 1;
            transform: translateY(30px);
            transition-duration: .3s;
        }
    </style>
</head>
<body class="relative min-h-screen bg-[#f6f1e8] text-[#24323f] antialiased selection:bg-[#0f766e] selection:text-white"
      style="font-family: 'Inter', sans-serif;"
      x-data="{ 
          favorites: JSON.parse(localStorage.getItem('bibliomanga_favs') || '[]'),
          favModalOpen: false,
          readingHistory: JSON.parse(localStorage.getItem('bibliomanga_history') || '[]'),
          animeHistory: JSON.parse(localStorage.getItem('biblioanime_history') || '[]'),
          isAnimeMode: {{ request()->is('anime') || request()->is('anime/*') ? 'true' : 'false' }},
          historyModalOpen: false,
          loadHistory() {
              this.readingHistory = JSON.parse(localStorage.getItem('bibliomanga_history') || '[]');
          },
          clearHistory() {
              this.readingHistory = [];
              localStorage.removeItem('bibliomanga_history');
          },
          removeHistory(mangaId) {
              this.readingHistory = this.readingHistory.filter(h => h.id !== mangaId);
              localStorage.setItem('bibliomanga_history', JSON.stringify(this.readingHistory));
          },
          loadAnimeHistory() {
              this.animeHistory = JSON.parse(localStorage.getItem('biblioanime_history') || '[]');
          },
          clearAnimeHistory() {
              this.animeHistory = [];
              localStorage.removeItem('biblioanime_history');
          },
          removeAnimeHistory(animeId) {
              this.animeHistory = this.animeHistory.filter(h => h.id !== animeId);
              localStorage.setItem('biblioanime_history', JSON.stringify(this.animeHistory));
          },
          openHistory() {
              if (this.isAnimeMode) { this.loadAnimeHistory(); } else { this.loadHistory(); }
              this.historyModalOpen = true;
          },
          get visibleHistory() {
              return this.isAnimeMode ? this.animeHistory : this.readingHistory;
          },
          historyHref(item) {
              if (this.isAnimeMode) {
                  return '/anime/' + item.id + '/watch?ep=' + encodeURIComponent(item.episodeId || '') + '&n=' + encodeURIComponent(item.episodeNumber || 1) + '&t=' + Math.max(0, Math.floor((item.positionSec || 0) - 5));
              }
              return '/manga/' + item.id + '/chapter/' + item.chapterId + '?ch=' + encodeURIComponent(item.chapterNumber || '') + '&source=' + encodeURIComponent(item.source || 'MangaDex');
          },
          removeHistoryItem(item) {
              if (this.isAnimeMode) { this.removeAnimeHistory(item.id); } else { this.removeHistory(item.id); }
          },
          clearVisibleHistory() {
              if (this.isAnimeMode) { this.clearAnimeHistory(); } else { this.clearHistory(); }
          },
          fmtTime(sec) {
              sec = Math.max(0, Math.floor(sec || 0));
              const h = Math.floor(sec / 3600);
              const m = Math.floor((sec % 3600) / 60);
              const s = sec % 60;
              const mm = h > 0 ? String(m).padStart(2, '0') : String(m);
              const ss = String(s).padStart(2, '0');
              return h > 0 ? h + ':' + mm + ':' + ss : mm + ':' + ss;
          },
          toggleFav(manga) {
              const idx = this.favorites.findIndex(f => f.id === manga.id);
              if (idx >= 0) {
                  this.favorites.splice(idx, 1);
              } else {
                  this.favorites.push({
                      id: manga.id,
                      title: manga.title,
                      coverUrl: manga.coverUrl,
                      status: manga.status
                  });
              }
              localStorage.setItem('bibliomanga_favs', JSON.stringify(this.favorites));
          },
          isFav(id) {
              return this.favorites.some(f => f.id === id);
          }
      }">

    <!-- Ambient background glows -->
    <div class="pointer-events-none fixed inset-0 overflow-hidden -z-10">
        <div class="absolute -top-40 -left-24 h-96 w-96 rounded-full bg-[#f1bc7a]/30 blur-3xl"></div>
        <div class="absolute top-24 right-0 h-[28rem] w-[28rem] translate-x-1/4 rounded-full bg-[#0f766e]/20 blur-3xl"></div>
        <div class="absolute bottom-10 left-1/2 h-[22rem] w-[22rem] -translate-x-1/2 rounded-full bg-[#fde68a]/30 blur-3xl"></div>
    </div>

    @php
        $isAnimeMode = request()->is('anime') || request()->is('anime/*');
        $homeRoute = $isAnimeMode ? route('anime.index') : route('manga.index');
        $liveSearchEndpoint = $isAnimeMode ? '/anime/api/live-search' : '/manga/api/live-search';
        $detailBase = $isAnimeMode ? '/anime/' : '/manga/';
        $searchAllBase = $isAnimeMode ? '/anime?q=' : '/manga?q=';
        $searchPlaceholder = $isAnimeMode ? 'Search anime titles...' : 'Search manga, manhwa, manhua titles...';
    @endphp

    <!-- Header Navigation -->
    <header class="sticky top-0 z-40 border-b border-[#e0d9c8] bg-white/85 backdrop-blur-md transition-all">
        <div class="mx-auto flex w-full max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            
            <!-- Logo Brand -->
            <a href="{{ $homeRoute }}" class="group">
                <div class="flex items-center gap-2">
                    <span class="text-xl sm:text-2xl font-bold tracking-tight text-[#172a37] transition-colors group-hover:text-[#0f766e]" style="font-family: 'Space Grotesk', sans-serif;">
                        {{ $isAnimeMode ? 'BiblioAnime' : 'BiblioManga' }}
                    </span>
                </div>
            </a>

            <!-- Search Bar Inline (Desktop Debounced Live Search) -->
            <div class="hidden md:block flex-1 max-w-md mx-4 relative"
                 x-data="{
                    query: '{{ request('q', '') }}',
                    results: [],
                    loading: false,
                    open: false,
                    async search() {
                        const q = this.query.trim();
                        if (q.length < 2) {
                            this.results = [];
                            this.open = false;
                            return;
                        }
                        this.loading = true;
                        try {
                            const res = await fetch('{{ $liveSearchEndpoint }}?q=' + encodeURIComponent(q));
                            const data = await res.json();
                            this.results = data.data || [];
                            this.open = this.results.length > 0;
                        } catch(e) {
                            this.results = [];
                        } finally {
                            this.loading = false;
                        }
                    }
                 }"
                 @click.away="open = false">
                <form action="{{ $homeRoute }}" method="GET" class="w-full">
                    @if(request('category'))
                        <input type="hidden" name="category" value="{{ request('category') }}">
                    @endif
                    <div class="relative w-full">
                        <span class="material-symbols-rounded absolute left-3.5 top-1/2 -translate-y-1/2 text-lg text-[#84948a]">search</span>
                        <input type="text" name="q" x-model="query"
                               @input.debounce.300ms="search()"
                               @focus="if(results.length > 0) open = true"
                               @keydown.escape="open = false"
                               placeholder="{{ $searchPlaceholder }}"
                               autocomplete="off"
                               class="w-full rounded-full border border-[#dcd2bd] bg-[#fbf9f4] py-2 pl-10 pr-9 text-xs sm:text-sm text-[#172a37] placeholder:text-[#9aa29a] focus:border-[#0f766e] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f766e]/20 transition" />
                        
                        <div x-show="loading" x-cloak class="absolute right-3.5 top-1/2 -translate-y-1/2">
                            <div class="h-4 w-4 animate-spin rounded-full border-2 border-[#0f766e] border-t-transparent"></div>
                        </div>
                    </div>
                </form>

                <!-- Floating Live Search Results Panel -->
                <div x-show="open && results.length > 0"
                     x-cloak
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                     x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                     x-transition:leave="transition ease-in duration-100"
                     x-transition:leave-start="opacity-100"
                     x-transition:leave-end="opacity-0"
                     class="absolute left-0 right-0 top-full mt-2 z-50 rounded-2xl border border-[#dcd2bd] bg-white/95 backdrop-blur-xl p-2 shadow-2xl space-y-1 max-h-[400px] overflow-y-auto custom-scroll">
                    
                    <template x-for="item in results" :key="item.id">
                        <a :href="'{{ $detailBase }}' + item.id"
                           class="flex items-center gap-3 p-2 rounded-xl transition hover:bg-[#f6ecda]/60 group">
                            <img :src="item.coverUrl || 'https://via.placeholder.com/60x80?text=No+Cover'"
                                 class="w-10 h-14 object-cover rounded-lg shadow-sm flex-shrink-0" />
                            <div class="min-w-0 flex-1">
                                <h4 class="text-xs sm:text-sm font-bold text-[#172a37] truncate group-hover:text-[#0f766e] transition-colors" x-text="item.title"></h4>
                                <div class="flex items-center gap-1.5 mt-1">
                                    <span class="rounded bg-[#0f766e]/10 px-1.5 py-0.5 text-[9px] font-bold text-[#0f766e] uppercase" x-text="item.status || 'Ongoing'"></span>
                                    <span x-show="item.format && item.format.toLowerCase() === 'novel'"
                                          class="rounded bg-amber-500/10 px-1.5 py-0.5 text-[9px] font-bold text-amber-700 uppercase">Novel</span>
                                    <span x-show="item.year" class="text-[10px] text-[#84948a]" x-text="item.year"></span>
                                </div>
                            </div>
                            <span class="material-symbols-rounded text-base text-[#84948a] group-hover:text-[#0f766e] transition">chevron_right</span>
                        </a>
                    </template>

                    <a :href="'{{ $searchAllBase }}' + encodeURIComponent(query)"
                       class="block w-full text-center py-2 text-xs font-bold text-[#0f766e] hover:bg-[#0f766e]/10 rounded-xl transition border-t border-[#e0d9c8] mt-1">
                        View all search results &rarr;
                    </a>
                </div>
            </div>

            <!-- Quick Navigation & Favorites -->
            <div class="flex items-center gap-2 sm:gap-3">
                <!-- History Button -->
                <button type="button" @click="openHistory()"
                        class="inline-flex items-center gap-1.5 rounded-full border border-[#0f766e]/30 bg-[#0f766e]/10 px-3.5 py-2.5 sm:py-1.5 text-xs font-semibold text-[#0f766e] transition hover:bg-[#0f766e]/20">
                    <span class="material-symbols-rounded text-base">history</span>
                    <span class="hidden sm:inline">History</span>
                    <span class="rounded-full bg-[#0f766e] text-white text-[10px] px-1.5 py-0.2" x-text="visibleHistory.length" x-show="visibleHistory.length > 0"></span>
                </button>

                <!-- Favorites Button: manga mode only (favorites are manga-only) -->
                @if(!$isAnimeMode)
                <button type="button" @click="favModalOpen = true"
                        class="inline-flex items-center gap-1 rounded-full border border-[#f97316]/30 bg-[#f97316]/10 px-3.5 py-2.5 sm:py-1.5 text-xs font-semibold text-[#ea580c] transition hover:bg-[#f97316]/20">
                    <span class="material-symbols-rounded text-base">bookmark</span>
                    <span class="hidden sm:inline">Favorites</span>
                    <span class="rounded-full bg-[#ea580c] text-white text-[10px] px-1.5 py-0.2" x-text="favorites.length"></span>
                </button>
                @endif

                <!-- Mode Switch: two explicit buttons (Manga / Anime) -->
                <div class="flex flex-shrink-0 items-center rounded-full border border-[#dcd2bd] bg-[#fbf9f4] p-1" role="group" aria-label="Select mode">
                    <a href="{{ route('manga.index') }}"
                       @if(!$isAnimeMode) aria-current="page" @endif
                       class="whitespace-nowrap rounded-full px-2 py-1 text-[10px] font-bold leading-none transition sm:px-3 sm:py-1.5 sm:text-xs {{ $isAnimeMode ? 'text-[#6f7e75] hover:text-[#172a37]' : 'bg-[#0f766e] text-white shadow-sm' }}">
                        Manga
                    </a>
                    <a href="{{ route('anime.index') }}"
                       @if($isAnimeMode) aria-current="page" @endif
                       class="whitespace-nowrap rounded-full px-2 py-1 text-[10px] font-bold leading-none transition sm:px-3 sm:py-1.5 sm:text-xs {{ $isAnimeMode ? 'bg-[#0f766e] text-white shadow-sm' : 'text-[#6f7e75] hover:text-[#172a37]' }}">
                        Anime
                    </a>
                </div>
            </div>
        </div>

        <!-- Mobile search row (Debounced Live Search) -->
        <div class="px-4 pb-3 md:hidden relative"
             x-data="{
                query: '{{ request('q', '') }}',
                results: [],
                loading: false,
                open: false,
                async search() {
                    const q = this.query.trim();
                    if (q.length < 2) {
                        this.results = [];
                        this.open = false;
                        return;
                    }
                    this.loading = true;
                    try {
                        const res = await fetch('{{ $liveSearchEndpoint }}?q=' + encodeURIComponent(q));
                        const data = await res.json();
                        this.results = data.data || [];
                        this.open = this.results.length > 0;
                    } catch(e) {
                        this.results = [];
                    } finally {
                        this.loading = false;
                    }
                }
             }"
             @click.away="open = false">
            <form action="{{ $homeRoute }}" method="GET" class="w-full">
                @if(request('category'))
                    <input type="hidden" name="category" value="{{ request('category') }}">
                @endif
                <div class="relative w-full">
                    <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-lg text-[#84948a]">search</span>
                    <input type="text" name="q" x-model="query"
                           @input.debounce.300ms="search()"
                           @focus="if(results.length > 0) open = true"
                           placeholder="{{ $searchPlaceholder }}"
                           autocomplete="off"
                           class="w-full rounded-full border border-[#dcd2bd] bg-[#fbf9f4] py-1.5 pl-9 pr-8 text-xs text-[#172a37] placeholder:text-[#9aa29a] focus:border-[#0f766e] focus:bg-white focus:outline-none" />
                    
                    <div x-show="loading" x-cloak class="absolute right-3 top-1/2 -translate-y-1/2">
                        <div class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-[#0f766e] border-t-transparent"></div>
                    </div>
                </div>
            </form>

            <!-- Floating Mobile Live Search Panel -->
            <div x-show="open && results.length > 0"
                 x-cloak
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0 translate-y-2 scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                 x-transition:leave="transition ease-in duration-100"
                 x-transition:leave-start="opacity-100"
                 x-transition:leave-end="opacity-0"
                 class="absolute left-4 right-4 top-full mt-1 z-50 rounded-2xl border border-[#dcd2bd] bg-white/95 backdrop-blur-xl p-2 shadow-2xl space-y-1 max-h-[350px] overflow-y-auto custom-scroll">
                <template x-for="item in results" :key="item.id">
                    <a :href="'{{ $detailBase }}' + item.id"
                       class="flex items-center gap-2.5 p-1.5 rounded-xl transition hover:bg-[#f6ecda]/60">
                        <img :src="item.coverUrl || 'https://via.placeholder.com/60x80?text=No+Cover'"
                             class="w-8 h-12 object-cover rounded-md shadow-sm flex-shrink-0" />
                        <div class="min-w-0 flex-1">
                            <h4 class="text-xs font-bold text-[#172a37] truncate" x-text="item.title"></h4>
                            <div class="flex items-center gap-1.5 mt-0.5">
                                <span class="rounded bg-[#0f766e]/10 px-1 py-0.2 text-[8px] font-bold text-[#0f766e] uppercase" x-text="item.status || 'Ongoing'"></span>
                                <span x-show="item.format && item.format.toLowerCase() === 'novel'"
                                      class="rounded bg-amber-500/10 px-1 py-0.2 text-[8px] font-bold text-amber-700 uppercase">Novel</span>
                            </div>
                        </div>
                    </a>
                </template>
                <a :href="'{{ $searchAllBase }}' + encodeURIComponent(query)"
                   class="block w-full text-center py-1.5 text-xs font-bold text-[#0f766e] hover:bg-[#0f766e]/10 rounded-xl transition border-t border-[#e0d9c8] mt-1">
                    View all results &rarr;
                </a>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="min-h-[80vh]">
        {{ $slot }}
    </main>

    <!-- Favorites Modal (localStorage) -->
    <div x-cloak x-show="favModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="w-full max-w-xl rounded-3xl border border-[#dcd2bd] bg-white p-6 shadow-2xl space-y-4 max-h-[85vh] flex flex-col"
             @click.away="favModalOpen = false">
            <div class="flex items-center justify-between border-b border-[#e0d9c8] pb-3">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-[#f97316]">bookmarks</span>
                    <h3 class="text-lg font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">Your Favorite Manga</h3>
                </div>
                <button type="button" @click="favModalOpen = false" class="text-gray-400 hover:text-gray-600">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>

            <!-- List favorit -->
            <div class="flex-1 overflow-y-auto space-y-3 custom-scroll pr-1">
                <template x-if="favorites.length === 0">
                    <div class="py-12 text-center text-[#6f7e75]">
                        <span class="material-symbols-rounded text-4xl text-[#c1cbbb]">bookmark_border</span>
                        <p class="mt-2 text-sm font-medium">No manga saved yet.</p>
                        <p class="text-xs text-[#9aa29a]">Click "Add to Favorites" on your favorite manga page!</p>
                    </div>
                </template>

                <template x-for="item in favorites" :key="item.id">
                    <div class="flex items-center justify-between gap-3 p-3 rounded-2xl border border-[#eaddc2] bg-[#fbf9f4] hover:bg-[#f6ecda]/50 transition">
                        <a :href="'/manga/' + item.id" class="flex items-center gap-3 flex-1 min-w-0">
                            <img :src="item.coverUrl || 'https://via.placeholder.com/150x200?text=No+Cover'" class="w-12 h-16 rounded-xl object-cover shadow-sm flex-shrink-0" />
                            <div class="min-w-0">
                                <h4 class="text-sm font-bold text-[#172a37] truncate" x-text="item.title"></h4>
                                <span class="inline-block mt-1 text-[10px] font-semibold uppercase px-2 py-0.5 rounded-full bg-[#0f766e]/10 text-[#0f766e]" x-text="item.status"></span>
                            </div>
                        </a>
                        <button type="button" @click="toggleFav(item)" class="btn-delete-uiverse" title="Remove from favorites">
                            <svg viewBox="0 0 448 512" class="delSvgIcon">
                                <path d="M135.2 17.7L128 32H32C14.3 32 0 46.3 0 64S14.3 96 32 96H416c17.7 0 32-14.3 32-32s-14.3-32-32-32H320l-7.2-14.3C307.4 6.8 296.3 0 284.2 0H163.8c-12.1 0-23.2 6.8-28.6 17.7zM416 128H32L53.2 467c1.6 25.3 22.6 45 47.9 45H346.9c25.3 0 46.3-19.7 47.9-45L416 128z"/>
                            </svg>
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- History Modal (localStorage) -->
    <div x-cloak x-show="historyModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="w-full max-w-xl rounded-3xl border border-[#dcd2bd] bg-white p-6 shadow-2xl space-y-4 max-h-[85vh] flex flex-col"
             @click.away="historyModalOpen = false">
            <div class="flex items-center justify-between border-b border-[#e0d9c8] pb-3">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-[#0f766e]">history</span>
                    <h3 class="text-lg font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;" x-text="isAnimeMode ? 'Watch History' : 'Reading History'">Reading History</h3>
                </div>
                <button type="button" @click="historyModalOpen = false" class="text-gray-400 hover:text-gray-600 transition">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>

            <!-- List history (mode-aware: manga di mode manga, anime di mode anime) -->
            <div class="flex-1 overflow-y-auto space-y-3 custom-scroll pr-1">
                <template x-if="visibleHistory.length === 0">
                    <div class="py-12 text-center text-[#6f7e75]">
                        <span class="material-symbols-rounded text-4xl text-[#c1cbbb]" x-text="isAnimeMode ? 'live_tv' : 'auto_stories'">auto_stories</span>
                        <p class="mt-2 text-sm font-medium" x-text="isAnimeMode ? 'No watch history yet.' : 'No reading history yet.'">No reading history yet.</p>
                        <p class="text-xs text-[#9aa29a]" x-text="isAnimeMode ? 'Watch any anime episode and your history will be saved here!' : 'Open any manga chapter and your history will be saved here!'">Open any manga chapter and your history will be saved here!</p>
                    </div>
                </template>

                <template x-for="item in visibleHistory" :key="item.id + '-' + (item.episodeId || item.chapterId || '')">
                    <div class="flex items-center justify-between gap-3 p-3 rounded-2xl border border-[#eaddc2] bg-[#fbf9f4] hover:bg-[#f6ecda]/50 transition">
                        <a :href="historyHref(item)" class="flex items-center gap-3 flex-1 min-w-0 group">
                            <img :src="item.coverUrl || 'https://via.placeholder.com/150x200?text=No+Cover'" class="w-12 h-16 rounded-xl object-cover shadow-sm flex-shrink-0" />
                            <div class="min-w-0 flex-1">
                                <h4 class="text-sm font-bold text-[#172a37] truncate group-hover:text-[#0f766e] transition-colors" x-text="item.title"></h4>
                                <template x-if="!isAnimeMode">
                                    <div class="flex items-center gap-2 mt-1">
                                        <span class="text-xs font-semibold text-[#0f766e]">
                                            Chapter <span x-text="item.chapterNumber"></span>
                                        </span>
                                        <span class="text-[10px] text-[#84948a]">•</span>
                                        <span class="text-[10px] text-[#84948a]" x-text="item.readAt"></span>
                                    </div>
                                </template>
                                <template x-if="isAnimeMode">
                                    <div class="mt-1 space-y-1.5">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-semibold text-[#0f766e]">
                                                Episode <span x-text="item.episodeNumber"></span>
                                            </span>
                                            <span class="text-[10px] text-[#84948a]">•</span>
                                            <span class="text-[10px] text-[#84948a]" x-text="fmtTime(item.positionSec) + ' / ' + fmtTime(item.durationSec)"></span>
                                        </div>
                                        <div class="h-1.5 w-full max-w-[220px] rounded-full bg-[#eaddc2] overflow-hidden">
                                            <div class="h-full rounded-full bg-[#0f766e]" :style="'width: ' + (item.durationSec > 0 ? Math.min(100, Math.round(100 * (item.positionSec || 0) / item.durationSec)) : 0) + '%'"></div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </a>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <a :href="historyHref(item)"
                               class="rounded-xl bg-[#0f766e] px-3.5 py-2 text-xs font-bold text-white transition hover:bg-[#115e59] shadow-sm">
                                Continue
                            </a>
                            <button type="button" @click="removeHistoryItem(item)" class="btn-delete-uiverse" title="Remove from history">
                                <svg viewBox="0 0 448 512" class="delSvgIcon">
                                    <path d="M135.2 17.7L128 32H32C14.3 32 0 46.3 0 64S14.3 96 32 96H416c17.7 0 32-14.3 32-32s-14.3-32-32-32H320l-7.2-14.3C307.4 6.8 296.3 0 284.2 0H163.8c-12.1 0-23.2 6.8-28.6 17.7zM416 128H32L53.2 467c1.6 25.3 22.6 45 47.9 45H346.9c25.3 0 46.3-19.7 47.9-45L416 128z"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                </template>

                <div x-show="visibleHistory.length > 0" class="pt-1 text-right">
                    <button type="button" @click="clearVisibleHistory()" class="text-xs font-bold text-[#ea580c] hover:underline">Clear all history</button>
                </div>
            </div>
        </div>
    </div>

</body>
</html>

