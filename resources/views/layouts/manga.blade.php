<!DOCTYPE html>
{{-- BUILD_MARKER: 2026-11-24-2 — bump this when only app/ or routes/ change, because Vercel skips those builds. --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title ?? 'BiblioManga - Read Manga Online Without Limits' }}</title>

    {{-- Animated site icon. The ICO is the static fallback for Safari. --}}
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/gif" href="{{ asset('favicon.gif') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <meta name="description" content="Read manga, manhwa, and manhua online for free on BiblioManga. Trending titles, full chapter lists, and fast updates.">
    <meta name="theme-color" content="#0f766e">
    <meta name="color-scheme" content="light dark">
    <script>
        (function () {
            try {
                var t = localStorage.getItem('biblio_theme');
                if (t === 'dark') document.documentElement.classList.add('dark');
            } catch (e) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    @if(request()->is('anime*'))
        <link rel="preconnect" href="https://s4.anilist.co" crossorigin>
        <link rel="preconnect" href="https://i.ytimg.com" crossorigin>
        <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    @endif
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:wght@400&display=swap" rel="stylesheet">

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
      style="font-family: 'Plus Jakarta Sans', system-ui, sans-serif;"
      x-data="{ 
          favorites: JSON.parse(localStorage.getItem('bibliomanga_favs') || '[]'),
          favModalOpen: false,
          readingHistory: JSON.parse(localStorage.getItem('bibliomanga_history') || '[]'),
          animeHistory: JSON.parse(localStorage.getItem('biblioanime_history') || '[]'),
          isAnimeMode: {{ request()->is('anime') || request()->is('anime/*') ? 'true' : 'false' }},
          historyModalOpen: false,
          settingsModalOpen: false,
          moreMenuOpen: false,
          darkMode: localStorage.getItem('biblio_theme') === 'dark',
          setDarkMode(on) {
              this.darkMode = !!on;
              document.documentElement.classList.toggle('dark', this.darkMode);
              try { localStorage.setItem('biblio_theme', this.darkMode ? 'dark' : 'light'); } catch (e) {}
          },
          clearFavorites() {
              this.favorites = [];
              localStorage.removeItem('bibliomanga_favs');
          },
          continueHref(item) {
              if (!item) return '/';
              if (item.episodeId !== undefined || item.episodeNumber !== undefined) {
                  return '/anime/' + item.id + '/watch?ep=' + encodeURIComponent(item.episodeId || '') + '&n=' + encodeURIComponent(item.episodeNumber || 1) + '&t=' + Math.max(0, Math.floor((item.positionSec || 0) - 5));
              }
              let url = '/manga/' + item.id + '/chapter/' + item.chapterId + '?ch=' + encodeURIComponent(item.chapterNumber || '') + '&source=' + encodeURIComponent(item.source || 'MangaDex');
              if (item.language) url += '&lang=' + encodeURIComponent(item.language);
              if (item.readPath) {
                  try { url += '&p=' + encodeURIComponent(btoa(item.readPath)); } catch (e) {}
              }
              return url;
          },
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
              let url = '/manga/' + item.id + '/chapter/' + item.chapterId + '?ch=' + encodeURIComponent(item.chapterNumber || '') + '&source=' + encodeURIComponent(item.source || 'MangaDex');
              if (item.language) url += '&lang=' + encodeURIComponent(item.language);
              if (item.readPath) {
                  try { url += '&p=' + encodeURIComponent(btoa(item.readPath)); } catch (e) {}
              }
              return url;
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

    <!-- Ambient background glows (pure radial gradients: same look, zero blur-3xl GPU cost) -->
    <div class="pointer-events-none fixed inset-0 -z-10"
         style="background:
             radial-gradient(36rem circle at 4% -6%, rgba(241, 188, 122, 0.28), transparent 65%),
             radial-gradient(40rem circle at 98% 22%, rgba(15, 118, 110, 0.16), transparent 65%),
             radial-gradient(30rem circle at 50% 94%, rgba(253, 230, 138, 0.28), transparent 65%);">
    </div>

    @php
        $isAnimeMode = request()->is('anime') || request()->is('anime/*');
        $homeRoute = $isAnimeMode ? route('anime.index') : route('manga.index');
        $liveSearchEndpoint = $isAnimeMode ? '/anime/api/live-search' : '/manga/api/live-search';
        $detailBase = $isAnimeMode ? '/anime/' : '/manga/';
        $searchAllBase = $isAnimeMode ? '/anime?q=' : '/manga?q=';
        $searchPlaceholder = $isAnimeMode ? 'Search anime titles...' : 'Search manga, manhwa, manhua titles...';
    @endphp

    <!-- App shell -->
    <a href="#siteView"
       class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-[90] focus:rounded-full focus:bg-[#0f766e] focus:px-4 focus:py-2 focus:text-sm focus:font-bold focus:text-white">
        Skip to content
    </a>

    <div class="relative flex min-h-screen flex-col">
        <!-- Standard top navbar (iQIYI-inspired clean bar) -->
        <header class="sticky top-0 z-40 border-b border-[#e0d9c8]/85 bg-[#f6f1e8]/95 backdrop-blur-sm">
            <div class="mx-auto flex h-14 w-full max-w-[1440px] items-center gap-4 px-4 sm:px-6 lg:h-16 lg:gap-6 lg:px-7">
                <!-- Pure text brand wordmark -->
                <a href="{{ $homeRoute }}" class="group flex flex-shrink-0 items-baseline select-none" title="BiblioCore">
                    <span class="text-xl font-extrabold tracking-tight text-[#0f766e] transition-colors group-hover:text-[#0d625b] lg:text-[23px]">Biblio</span><span class="text-xl font-extrabold tracking-tight text-[#172a37] lg:text-[23px]">Core</span><span class="ml-0.5 text-xl font-black text-[#f97316] lg:text-[23px]">.</span>
                </a>

                <!-- Desktop text nav links -->
                <nav class="hidden items-center gap-6 lg:flex" aria-label="Main navigation">
                    <a href="{{ route('manga.index') }}"
                       class="relative py-1 text-sm font-bold transition {{ !$isAnimeMode && request('category') !== 'trending' ? 'text-[#0f766e]' : 'text-[#4c5b54] hover:text-[#172a37]' }}"
                       @if(!$isAnimeMode) aria-current="page" @endif>
                        Manga
                        @if(!$isAnimeMode)
                            <span class="absolute inset-x-0 -bottom-1 h-0.5 rounded-full bg-[#0f766e]"></span>
                        @endif
                    </a>
                    <a href="{{ route('anime.index') }}"
                       class="relative py-1 text-sm font-bold transition {{ $isAnimeMode && request('category') !== 'trending' ? 'text-[#0f766e]' : 'text-[#4c5b54] hover:text-[#172a37]' }}"
                       @if($isAnimeMode) aria-current="page" @endif>
                        Anime
                        @if($isAnimeMode)
                            <span class="absolute inset-x-0 -bottom-1 h-0.5 rounded-full bg-[#0f766e]"></span>
                        @endif
                    </a>
                    <a href="{{ $isAnimeMode ? route('anime.index', ['category' => 'trending']) : route('manga.index', ['category' => 'trending']) }}"
                       class="py-1 text-sm font-bold transition {{ request('category') === 'trending' ? 'text-[#0f766e]' : 'text-[#4c5b54] hover:text-[#172a37]' }}">
                        Trending
                    </a>

                    <div class="relative" @click.away="moreMenuOpen = false" @keydown.escape="moreMenuOpen = false">
                        <button type="button" @click="moreMenuOpen = !moreMenuOpen"
                                class="inline-flex items-center gap-0.5 py-1 text-sm font-bold text-[#4c5b54] transition hover:text-[#172a37]">
                            <span>More</span>
                            <span class="material-symbols-rounded text-[18px] transition-transform" :class="moreMenuOpen ? 'rotate-180' : ''">arrow_drop_down</span>
                        </button>
                        <div x-show="moreMenuOpen" x-cloak
                             x-transition:enter="transition ease-out duration-120"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="absolute left-0 top-full z-50 mt-2 w-44 rounded-2xl border border-[#dcd2bd] bg-white p-1.5 shadow-xl">
                            <a href="{{ $isAnimeMode ? route('anime.index', ['category' => 'popular']) : route('manga.index', ['category' => 'popular']) }}"
                               class="flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-bold text-[#37474f] transition hover:bg-[#f6f1e8] hover:text-[#0f766e]">
                                Popular
                            </a>
                            <a href="{{ $isAnimeMode ? route('anime.index', ['category' => 'ongoing']) : route('manga.index', ['category' => 'ongoing']) }}"
                               class="flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-bold text-[#37474f] transition hover:bg-[#f6f1e8] hover:text-[#0f766e]">
                                Ongoing
                            </a>
                            <a href="{{ $isAnimeMode ? route('anime.index', ['category' => 'completed']) : route('manga.index', ['category' => 'completed']) }}"
                               class="flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-bold text-[#37474f] transition hover:bg-[#f6f1e8] hover:text-[#0f766e]">
                                Completed
                            </a>
                            @if($isAnimeMode)
                                <a href="{{ route('anime.index', ['category' => 'top']) }}"
                                   class="flex items-center gap-2 rounded-xl px-3 py-2 text-xs font-bold text-[#37474f] transition hover:bg-[#f6f1e8] hover:text-[#0f766e]">
                                    Top Rated
                                </a>
                            @endif
                        </div>
                    </div>
                </nav>

                <!-- Right cluster: Search + Icon-label actions (History, Favorites, Settings) -->
                <div class="ml-auto flex flex-1 items-center justify-end gap-4 lg:gap-5">
                    <div class="hidden min-w-0 flex-1 justify-end md:flex">
                        @include('partials.live-search', ['variant' => 'topbar'])
                    </div>

                    <button type="button" @click="settingsModalOpen = true"
                            class="inline-flex h-9 w-9 items-center justify-center rounded-xl border border-[#dcd2bd] bg-white/80 text-[#4c5b54] transition hover:text-[#0f766e] lg:hidden"
                            aria-label="Settings">
                        <span class="material-symbols-rounded text-[20px]">settings</span>
                    </button>

                    <div class="hidden flex-shrink-0 items-center gap-4 lg:flex">
                        <button type="button" @click="openHistory()"
                                class="relative flex flex-col items-center justify-center gap-0.5 text-[#4c5b54] transition hover:text-[#0f766e]"
                                :class="historyModalOpen ? 'text-[#0f766e]' : ''">
                            <span class="material-symbols-rounded text-[21px] leading-none">history</span>
                            <span class="text-[11px] font-semibold leading-none">History</span>
                            <span class="absolute -right-1.5 -top-1 grid h-4 min-w-[16px] place-items-center rounded-full bg-[#0f766e] px-1 text-[9px] font-bold leading-none text-white"
                                  x-show="visibleHistory.length > 0" x-text="visibleHistory.length"></span>
                        </button>

                        <button type="button" @click="favModalOpen = true"
                                class="relative flex flex-col items-center justify-center gap-0.5 text-[#4c5b54] transition hover:text-[#ea580c]"
                                :class="favModalOpen ? 'text-[#ea580c]' : ''">
                            <span class="material-symbols-rounded text-[21px] leading-none">bookmark</span>
                            <span class="text-[11px] font-semibold leading-none">Favorites</span>
                            <span class="absolute -right-1.5 -top-1 grid h-4 min-w-[16px] place-items-center rounded-full bg-[#ea580c] px-1 text-[9px] font-bold leading-none text-white"
                                  x-show="favorites.length > 0" x-text="favorites.length"></span>
                        </button>

                        <button type="button" @click="settingsModalOpen = true"
                                class="flex flex-col items-center justify-center gap-0.5 text-[#4c5b54] transition hover:text-[#0f766e]"
                                :class="settingsModalOpen ? 'text-[#0f766e]' : ''">
                            <span class="material-symbols-rounded text-[21px] leading-none">settings</span>
                            <span class="text-[11px] font-semibold leading-none">Settings</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Mobile search row: always visible -->
            <div class="px-4 pb-2.5 sm:px-6 md:hidden">
                @include('partials.live-search', ['variant' => 'mobile'])
            </div>
        </header>

        <main id="siteView" class="mx-auto w-full max-w-[1440px] flex-1 px-4 py-5 pb-28 sm:px-6 lg:px-7 lg:py-8 lg:pb-14">

        {{ $slot }}
        </main>
    </div>

    <!-- Mobile bottom navigation (sleek docked bar) -->
    <nav class="mobile-dock fixed inset-x-0 bottom-0 z-40 border-t border-[#e0d9c8] bg-[#faf7f0]/95 shadow-[0_-6px_24px_-12px_rgba(23,42,55,0.16)] backdrop-blur-md pb-safe lg:hidden"
         aria-label="Navigasi utama">
        <div class="mx-auto grid max-w-md grid-cols-4">
            <a href="{{ route('manga.index') }}"
               class="relative flex flex-col items-center justify-center gap-0.5 pt-2 pb-1.5 text-[10px] transition active:scale-95 {{ !$isAnimeMode ? 'font-bold text-[#0f766e]' : 'font-medium text-[#6f7e75]' }}"
               @if(!$isAnimeMode) aria-current="page" @endif>
                @if(!$isAnimeMode)
                    <span class="absolute top-0 h-[2.5px] w-8 rounded-b-full bg-[#0f766e]"></span>
                @endif
                <span class="grid h-7 w-12 place-items-center rounded-full transition {{ !$isAnimeMode ? 'bg-[#0f766e]/12' : '' }}">
                    <span class="material-symbols-rounded text-[21px] leading-none">menu_book</span>
                </span>
                <span>Manga</span>
            </a>

            <a href="{{ route('anime.index') }}"
               class="relative flex flex-col items-center justify-center gap-0.5 pt-2 pb-1.5 text-[10px] transition active:scale-95 {{ $isAnimeMode ? 'font-bold text-[#0f766e]' : 'font-medium text-[#6f7e75]' }}"
               @if($isAnimeMode) aria-current="page" @endif>
                @if($isAnimeMode)
                    <span class="absolute top-0 h-[2.5px] w-8 rounded-b-full bg-[#0f766e]"></span>
                @endif
                <span class="grid h-7 w-12 place-items-center rounded-full transition {{ $isAnimeMode ? 'bg-[#0f766e]/12' : '' }}">
                    <span class="material-symbols-rounded text-[21px] leading-none">smart_display</span>
                </span>
                <span>Anime</span>
            </a>

            <button type="button" @click="openHistory()"
                    class="relative flex flex-col items-center justify-center gap-0.5 pt-2 pb-1.5 text-[10px] transition active:scale-95"
                    :class="historyModalOpen ? 'font-bold text-[#0f766e]' : 'font-medium text-[#6f7e75]'">
                <span x-show="historyModalOpen" x-cloak class="absolute top-0 h-[2.5px] w-8 rounded-b-full bg-[#0f766e]"></span>
                <span class="relative grid h-7 w-12 place-items-center rounded-full transition"
                      :class="historyModalOpen ? 'bg-[#0f766e]/12' : ''">
                    <span class="material-symbols-rounded text-[21px] leading-none">history</span>
                    <span class="absolute right-1.5 top-0 grid h-4 min-w-[16px] place-items-center rounded-full bg-[#0f766e] px-1 text-[9px] font-bold leading-none text-white ring-2 ring-[#faf7f0]"
                          x-show="visibleHistory.length > 0" x-text="visibleHistory.length"></span>
                </span>
                <span>History</span>
            </button>

            <button type="button" @click="favModalOpen = true"
                    class="relative flex flex-col items-center justify-center gap-0.5 pt-2 pb-1.5 text-[10px] transition active:scale-95"
                    :class="favModalOpen ? 'font-bold text-[#ea580c]' : 'font-medium text-[#6f7e75]'">
                <span x-show="favModalOpen" x-cloak class="absolute top-0 h-[2.5px] w-8 rounded-b-full bg-[#ea580c]"></span>
                <span class="relative grid h-7 w-12 place-items-center rounded-full transition"
                      :class="favModalOpen ? 'bg-[#ea580c]/12' : ''">
                    <span class="material-symbols-rounded text-[21px] leading-none">bookmark</span>
                    <span class="absolute right-1.5 top-0 grid h-4 min-w-[16px] place-items-center rounded-full bg-[#ea580c] px-1 text-[9px] font-bold leading-none text-white ring-2 ring-[#faf7f0]"
                          x-show="favorites.length > 0" x-text="favorites.length"></span>
                </span>
                <span>Favorites</span>
            </button>
        </div>
    </nav>

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
                    <h3 class="text-lg font-bold text-[#172a37]" style="font-family: 'Plus Jakarta Sans', sans-serif;">Your Favorite Manga</h3>
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
                            <img :src="item.coverUrl || 'https://via.placeholder.com/150x200?text=No+Cover'" loading="lazy" decoding="async" class="w-12 h-16 rounded-xl object-cover shadow-sm flex-shrink-0" />
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
                    <h3 class="text-lg font-bold text-[#172a37]" style="font-family: 'Plus Jakarta Sans', sans-serif;" x-text="isAnimeMode ? 'Watch History' : 'Reading History'">Reading History</h3>
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
                            <img :src="item.coverUrl || 'https://via.placeholder.com/150x200?text=No+Cover'" loading="lazy" decoding="async" class="w-12 h-16 rounded-xl object-cover shadow-sm flex-shrink-0" />
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

    <!-- Settings Modal -->
    <div x-cloak x-show="settingsModalOpen" class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
         @keydown.escape.window="settingsModalOpen = false"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div class="w-full max-w-md rounded-3xl border border-[#dcd2bd] bg-white p-6 shadow-2xl space-y-5"
             @click.away="settingsModalOpen = false">
            <div class="flex items-center justify-between border-b border-[#e0d9c8] pb-3">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-rounded text-[#0f766e]">settings</span>
                    <h3 class="text-lg font-bold text-[#172a37]">Settings</h3>
                </div>
                <button type="button" @click="settingsModalOpen = false" class="text-gray-400 hover:text-gray-600 transition">
                    <span class="material-symbols-rounded">close</span>
                </button>
            </div>

            <div class="space-y-4">
                <!-- Appearance / Dark Mode -->
                <div class="flex items-center justify-between gap-3 rounded-2xl border border-[#eaddc2] bg-[#fbf9f4] p-3.5">
                    <div>
                        <p class="text-sm font-bold text-[#172a37]">Appearance</p>
                        <p class="text-xs text-[#6f7e75]">Switch between Light and Dark mode</p>
                    </div>
                    <div class="flex items-center gap-1 rounded-full border border-[#dcd2bd] bg-white p-1">
                        <button type="button" @click="setDarkMode(false)"
                                class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-bold transition"
                                :class="!darkMode ? 'bg-[#0f766e] text-white shadow-sm' : 'text-[#5f6b63] hover:text-[#172a37]'">
                            <span class="material-symbols-rounded text-[15px]">light_mode</span>
                            Light
                        </button>
                        <button type="button" @click="setDarkMode(true)"
                                class="inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-bold transition"
                                :class="darkMode ? 'bg-[#0f766e] text-white shadow-sm' : 'text-[#5f6b63] hover:text-[#172a37]'">
                            <span class="material-symbols-rounded text-[15px]">dark_mode</span>
                            Dark
                        </button>
                    </div>
                </div>

                <!-- Local data management -->
                <div class="space-y-2 rounded-2xl border border-[#eaddc2] bg-[#fbf9f4] p-3.5">
                    <p class="text-sm font-bold text-[#172a37]">Saved Data</p>
                    <p class="text-xs text-[#6f7e75]">History and favorites are stored locally in your browser.</p>
                    <div class="flex flex-wrap gap-2 pt-1.5">
                        <button type="button" @click="clearHistory(); clearAnimeHistory()"
                                class="rounded-xl border border-[#dcd2bd] bg-white px-3 py-1.5 text-xs font-bold text-[#37474f] transition hover:border-[#ea580c] hover:text-[#ea580c]">
                            Clear History
                        </button>
                        <button type="button" @click="clearFavorites()"
                                class="rounded-xl border border-[#dcd2bd] bg-white px-3 py-1.5 text-xs font-bold text-[#37474f] transition hover:border-[#ea580c] hover:text-[#ea580c]">
                            Clear Favorites
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('partials.animated-favicon')
</body>
</html>

