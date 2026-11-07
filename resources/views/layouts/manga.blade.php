<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'BiblioManga — Baca Manga Online Tanpa Batas' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:wght@300;400;500;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        [x-cloak] { display: none !important; }
        .custom-scroll::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scroll::-webkit-scrollbar-track { background: #f1ebd8; border-radius: 9999px; }
        .custom-scroll::-webkit-scrollbar-thumb { background: #0f766e; border-radius: 9999px; }
        .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .line-clamp-3 { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    </style>
</head>
<body class="relative min-h-screen bg-[#f6f1e8] text-[#24323f] antialiased selection:bg-[#0f766e] selection:text-white"
      style="font-family: 'Inter', sans-serif;"
      x-data="{ 
          favorites: JSON.parse(localStorage.getItem('bibliomanga_favs') || '[]'),
          favModalOpen: false,
          readingHistory: JSON.parse(localStorage.getItem('bibliomanga_history') || '[]'),
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

    <!-- Header Navigation -->
    <header class="sticky top-0 z-40 border-b border-[#e0d9c8] bg-white/85 backdrop-blur-md transition-all">
        <div class="mx-auto flex w-full max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            
            <!-- Logo Brand -->
            <a href="{{ route('manga.index') }}" class="group">
                <div class="flex items-center gap-2">
                    <span class="text-xl sm:text-2xl font-bold tracking-tight text-[#172a37] transition-colors group-hover:text-[#0f766e]" style="font-family: 'Space Grotesk', sans-serif;">
                        BiblioManga
                    </span>
                    <span class="hidden sm:inline-block rounded-full bg-[#f97316]/10 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-[#ea580c]">
                        Free
                    </span>
                </div>
                <p class="text-[11px] uppercase tracking-[0.25em] text-[#5f6b63]">
                    No Login • Unlimited Read
                </p>
            </a>

            <!-- Search Bar Inline -->
            <form action="{{ route('manga.index') }}" method="GET" class="hidden md:flex flex-1 max-w-md mx-4">
                @if(request('category'))
                    <input type="hidden" name="category" value="{{ request('category') }}">
                @endif
                <div class="relative w-full">
                    <span class="material-symbols-rounded absolute left-3.5 top-1/2 -translate-y-1/2 text-lg text-[#84948a]">search</span>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari judul manga, manhwa, manhua..."
                           class="w-full rounded-full border border-[#dcd2bd] bg-[#fbf9f4] py-2 pl-10 pr-4 text-xs sm:text-sm text-[#172a37] placeholder:text-[#9aa29a] focus:border-[#0f766e] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f766e]/20 transition" />
                </div>
            </form>

            <!-- Quick Navigation & Favorites -->
            <div class="flex items-center gap-2 sm:gap-3">
                <!-- History Button -->
                <button type="button" @click="loadHistory(); historyModalOpen = true"
                        class="inline-flex items-center gap-1.5 rounded-full border border-[#0f766e]/30 bg-[#0f766e]/10 px-3.5 py-1.5 text-xs font-semibold text-[#0f766e] transition hover:bg-[#0f766e]/20">
                    <span class="material-symbols-rounded text-base">history</span>
                    <span class="hidden sm:inline">History</span>
                    <span class="rounded-full bg-[#0f766e] text-white text-[10px] px-1.5 py-0.2" x-text="readingHistory.length" x-show="readingHistory.length > 0"></span>
                </button>

                <!-- Favorit Button -->
                <button type="button" @click="favModalOpen = true"
                        class="inline-flex items-center gap-1 rounded-full border border-[#f97316]/30 bg-[#f97316]/10 px-3.5 py-1.5 text-xs font-semibold text-[#ea580c] transition hover:bg-[#f97316]/20">
                    <span class="material-symbols-rounded text-base">bookmark</span>
                    <span class="hidden sm:inline">Favorit</span>
                    <span class="rounded-full bg-[#ea580c] text-white text-[10px] px-1.5 py-0.2" x-text="favorites.length"></span>
                </button>
            </div>
        </div>

        <!-- Mobile search row -->
        <div class="px-4 pb-3 md:hidden">
            <form action="{{ route('manga.index') }}" method="GET" class="w-full">
                @if(request('category'))
                    <input type="hidden" name="category" value="{{ request('category') }}">
                @endif
                <div class="relative w-full">
                    <span class="material-symbols-rounded absolute left-3 top-1/2 -translate-y-1/2 text-lg text-[#84948a]">search</span>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Cari judul manga..."
                           class="w-full rounded-full border border-[#dcd2bd] bg-[#fbf9f4] py-1.5 pl-9 pr-3 text-xs text-[#172a37] placeholder:text-[#9aa29a] focus:border-[#0f766e] focus:bg-white focus:outline-none" />
                </div>
            </form>
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
                    <h3 class="text-lg font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">Manga Favorit Kamu</h3>
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
                        <p class="mt-2 text-sm font-medium">Belum ada manga yang kamu simpan.</p>
                        <p class="text-xs text-[#9aa29a]">Klik tombol "Simpan ke Favorit" pada halaman manga favoritmu!</p>
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
                        <button type="button" @click="toggleFav(item)" class="p-2 text-red-500 hover:bg-red-50 rounded-xl transition" title="Hapus">
                            <span class="material-symbols-rounded text-base">delete</span>
                        </button>
                    </div>
                </template>
            </div>

            <div class="pt-3 border-t border-[#e0d9c8] flex justify-between items-center text-xs text-[#6b766f]">
                <span>Tersimpan di browser lokalmu (tanpa perlu akun).</span>
                <button type="button" @click="favModalOpen = false" class="px-4 py-2 rounded-full bg-[#0f766e] text-white font-semibold">Tutup</button>
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
                    <h3 class="text-lg font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">Riwayat Membaca</h3>
                </div>
                <div class="flex items-center gap-3">
                    <button type="button" @click="clearHistory()" x-show="readingHistory.length > 0"
                            class="text-xs font-bold text-red-500 hover:text-red-700 transition hover:underline">
                        Hapus Semua
                    </button>
                    <button type="button" @click="historyModalOpen = false" class="text-gray-400 hover:text-gray-600">
                        <span class="material-symbols-rounded">close</span>
                    </button>
                </div>
            </div>

            <!-- List history -->
            <div class="flex-1 overflow-y-auto space-y-3 custom-scroll pr-1">
                <template x-if="readingHistory.length === 0">
                    <div class="py-12 text-center text-[#6f7e75]">
                        <span class="material-symbols-rounded text-4xl text-[#c1cbbb]">auto_stories</span>
                        <p class="mt-2 text-sm font-medium">Belum ada riwayat bacaan.</p>
                        <p class="text-xs text-[#9aa29a]">Buka chapter manga apa saja dan riwayatmu otomatis tersimpan di sini!</p>
                    </div>
                </template>

                <template x-for="item in readingHistory" :key="item.id">
                    <div class="flex items-center justify-between gap-3 p-3 rounded-2xl border border-[#eaddc2] bg-[#fbf9f4] hover:bg-[#f6ecda]/50 transition">
                        <a :href="'/manga/' + item.id + '/chapter/' + item.chapterId" class="flex items-center gap-3 flex-1 min-w-0 group">
                            <img :src="item.coverUrl || 'https://via.placeholder.com/150x200?text=No+Cover'" class="w-12 h-16 rounded-xl object-cover shadow-sm flex-shrink-0" />
                            <div class="min-w-0">
                                <h4 class="text-sm font-bold text-[#172a37] truncate group-hover:text-[#0f766e] transition-colors" x-text="item.title"></h4>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-xs font-semibold text-[#0f766e]">
                                        Chapter <span x-text="item.chapterNumber"></span>
                                    </span>
                                    <span class="text-[10px] text-[#84948a]">•</span>
                                    <span class="text-[10px] text-[#84948a]" x-text="item.readAt"></span>
                                </div>
                            </div>
                        </a>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            <a :href="'/manga/' + item.id + '/chapter/' + item.chapterId"
                               class="rounded-xl bg-[#0f766e] px-3 py-1.5 text-xs font-bold text-white transition hover:bg-[#115e59] shadow-sm">
                                Lanjut
                            </a>
                            <button type="button" @click="removeHistory(item.id)" class="p-1.5 text-red-500 hover:bg-red-50 rounded-xl transition" title="Hapus dari riwayat">
                                <span class="material-symbols-rounded text-base">delete</span>
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <div class="pt-3 border-t border-[#e0d9c8] flex justify-between items-center text-xs text-[#6b766f]">
                <span>Tersimpan di browser lokalmu (tanpa perlu akun).</span>
                <button type="button" @click="historyModalOpen = false" class="px-4 py-2 rounded-full bg-[#0f766e] text-white font-semibold">Tutup</button>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="mt-20 border-t border-[#e0d9c8] bg-white/70 py-10">
        <div class="mx-auto w-full max-w-7xl px-4 sm:px-6 flex flex-col md:flex-row items-center justify-between gap-6 text-center md:text-left">
            <div>
                <p class="text-base font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">
                    BiblioManga — Open Manga Platform
                </p>
                <p class="mt-1 text-xs text-[#5f6b63]">
                    Portal baca komik & manga online gratis. Terhubung langsung dengan MangaDex API untuk update chapter tercepat Bahasa Indonesia & Bahasa Inggris.
                </p>
            </div>
            <div class="flex items-center gap-3 text-xs text-[#6b766f]">
                <span class="rounded-full bg-[#0f766e]/10 px-3 py-1 font-semibold text-[#0f766e]">Tanpa Login</span>
                <span class="rounded-full bg-[#f97316]/10 px-3 py-1 font-semibold text-[#ea580c]">Tanpa Iklan Pop-up</span>
                <span class="rounded-full bg-[#115e59]/10 px-3 py-1 font-semibold text-[#115e59]">DNS Bypass Ready</span>
            </div>
        </div>
    </footer>

</body>
</html>

