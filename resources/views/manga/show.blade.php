<x-manga-layout :title="$manga['title'] . ' — Baca Manga Online di BiblioManga'">
    <div class="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6 space-y-8"
         x-data="{ 
            activeTab: '{{ !empty($indoChapters) ? 'id' : (!empty($enChapters) ? 'en' : 'all') }}',
            expandedDesc: false
         }">

        <!-- Navigation Breadcrumb -->
        <div class="flex items-center gap-2 text-xs text-[#6f7e75]">
            <a href="{{ route('manga.index') }}" class="hover:text-[#0f766e] flex items-center gap-1 font-semibold">
                <span class="material-symbols-rounded text-sm">arrow_back</span>
                Kembali ke Katalog
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
                        <span x-text="isFav('{{ $manga['id'] }}') ? 'Tersimpan di Favorit' : 'Simpan ke Favorit'"></span>
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
                                    Rilis: {{ $manga['year'] }}
                                </span>
                            @endif
                        </div>

                        <h1 class="text-2xl sm:text-4xl font-extrabold text-[#172a37] tracking-tight leading-tight" style="font-family: 'Space Grotesk', sans-serif;">
                            {{ $manga['title'] }}
                        </h1>

                        @if(!empty($manga['altTitles']))
                            <p class="text-xs text-[#6f7e75] italic line-clamp-2">
                                Nama lain: {{ implode(' • ', array_slice($manga['altTitles'], 0, 3)) }}
                            </p>
                        @endif

                        <div class="flex flex-wrap gap-4 text-xs text-[#4c5b54] pt-1 border-t border-[#e0d9c8]">
                            <div>
                                <span class="text-[#84948a] uppercase tracking-wider">Penulis:</span>
                                <span class="font-semibold text-[#172a37]">{{ $manga['authors'] }}</span>
                            </div>
                            <div>
                                <span class="text-[#84948a] uppercase tracking-wider">Ilustrator:</span>
                                <span class="font-semibold text-[#172a37]">{{ $manga['artists'] }}</span>
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
                            <h3 class="text-xs font-bold uppercase tracking-wider text-[#84948a]">Sinopsis</h3>
                            <div class="mt-1 text-sm leading-relaxed text-[#4c5b54] relative"
                                 :class="expandedDesc ? '' : 'line-clamp-3'">
                                {{ $manga['description'] }}
                            </div>
                            @if(strlen($manga['description']) > 200)
                                <button type="button" @click="expandedDesc = !expandedDesc"
                                        class="mt-1 text-xs font-bold text-[#0f766e] hover:underline focus:outline-none"
                                        x-text="expandedDesc ? 'Sembunyikan' : 'Baca Selengkapnya...'">
                                </button>
                            @endif
                        </div>
                    </div>

                    <!-- Quick CTA Start Reading -->
                    <div class="flex flex-wrap items-center gap-3 pt-6 border-t border-[#e0d9c8]">
                        @if($firstChapter)
                            <a href="{{ route('manga.read', ['id' => $manga['id'], 'chapterId' => $firstChapter['id']]) }}"
                               class="inline-flex items-center gap-2 rounded-full bg-[#0f766e] px-6 py-3 text-xs sm:text-sm font-bold uppercase tracking-wider text-white shadow-lg shadow-[#0f766e]/25 transition hover:scale-105 hover:bg-[#115e59]">
                                <span class="material-symbols-rounded text-base">play_arrow</span>
                                Baca Chapter Pertama (Ch. {{ $firstChapter['chapter'] }})
                            </a>
                        @endif

                        @if($latestChapter && $latestChapter['id'] !== ($firstChapter['id'] ?? null))
                            <a href="{{ route('manga.read', ['id' => $manga['id'], 'chapterId' => $latestChapter['id']]) }}"
                               class="inline-flex items-center gap-2 rounded-full border border-[#dcd2bd] bg-white px-5 py-3 text-xs sm:text-sm font-bold uppercase tracking-wider text-[#172a37] transition hover:border-[#0f766e] hover:text-[#0f766e]">
                                <span class="material-symbols-rounded text-base">skip_next</span>
                                Chapter Terbaru (Ch. {{ $latestChapter['chapter'] }})
                            </a>
                        @endif
                    </div>
                </div>

            </div>
        </section>

        <!-- Chapter List Section -->
        <section class="rounded-[2.5rem] border border-[#dcd2bd] bg-white p-6 sm:p-8 shadow-sm space-y-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-[#e0d9c8] pb-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-xl sm:text-2xl font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">
                            Daftar Chapter
                        </h2>
                        <span class="rounded-full bg-[#0f766e]/10 px-2.5 py-0.5 text-[10px] font-bold text-[#0f766e] uppercase tracking-wider">
                            Multi-Source Hybrid
                        </span>
                    </div>
                    <p class="text-xs text-[#6f7e75] mt-1">
                        Menggabungkan chapter dari MangaDex, Komiku, MangaPill, KomikIndo & MangaNato secara otomatis
                    </p>
                </div>

                <!-- Language Tabs -->
                <div class="flex items-center gap-1.5 rounded-full border border-[#dcd2bd] bg-[#fbf9f4] p-1">
                    <button type="button" @click="activeTab = 'id'"
                            class="rounded-full px-3.5 py-1.5 text-xs font-bold transition flex items-center gap-1"
                            :class="activeTab === 'id' ? 'bg-[#0f766e] text-white shadow-sm' : 'text-[#4c5b54] hover:text-[#0f766e]'">
                        <span>🇮🇩 Indo</span>
                        <span class="rounded-full bg-white/20 px-1.5 text-[10px]">{{ count($indoChapters) }}</span>
                    </button>
                    <button type="button" @click="activeTab = 'en'"
                            class="rounded-full px-3.5 py-1.5 text-xs font-bold transition flex items-center gap-1"
                            :class="activeTab === 'en' ? 'bg-[#0f766e] text-white shadow-sm' : 'text-[#4c5b54] hover:text-[#0f766e]'">
                        <span>🇬🇧 English</span>
                        <span class="rounded-full bg-white/20 px-1.5 text-[10px]">{{ count($enChapters) }}</span>
                    </button>
                    <button type="button" @click="activeTab = 'all'"
                            class="rounded-full px-3.5 py-1.5 text-xs font-bold transition flex items-center gap-1"
                            :class="activeTab === 'all' ? 'bg-[#0f766e] text-white shadow-sm' : 'text-[#4c5b54] hover:text-[#0f766e]'">
                        <span>Semua</span>
                        <span class="rounded-full bg-white/20 px-1.5 text-[10px]">{{ count($allChapters) }}</span>
                    </button>
                </div>
            </div>

            <!-- Chapter Table / List -->
            <div class="space-y-2 max-h-[600px] overflow-y-auto pr-1 custom-scroll">
                @php
                    $renderChapters = function($chapters) use ($manga) {
                        if (empty($chapters)) {
                            return '<div class="py-12 text-center text-[#84948a] text-sm">Tidak ada chapter untuk bahasa ini.</div>';
                        }
                        $html = '';
                        foreach ($chapters as $chap) {
                            $isExt = !empty($chap['externalUrl']);
                            $readUrl = $isExt ? $chap['externalUrl'] : route('manga.read', ['id' => $manga['id'], 'chapterId' => $chap['id']]);
                            $target = $isExt ? 'target="_blank"' : '';
                            $langFlag = $chap['language'] === 'id' ? '🇮🇩 ID' : ($chap['language'] === 'en' ? '🇬🇧 EN' : strtoupper($chap['language']));

                            $html .= '<div class="group flex items-center justify-between gap-4 rounded-2xl border border-[#eaddc2] bg-[#fbf9f4] p-3.5 transition hover:border-[#0f766e] hover:bg-white hover:shadow-sm">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="font-bold text-sm text-[#172a37] group-hover:text-[#0f766e] transition-colors">
                                            Chapter ' . e($chap['chapter']) . '
                                        </span>
                                        <span class="rounded-md bg-[#0f766e]/10 px-1.5 py-0.5 text-[10px] font-bold text-[#0f766e]">
                                            ' . $langFlag . '
                                        </span>
                                        <span class="rounded-md bg-[#f97316]/10 px-1.5 py-0.5 text-[10px] font-bold text-[#ea580c]">
                                            ' . e($chap['provider'] ?? 'MangaDex') . '
                                        </span>
                                        ' . ($chap['title'] ? '<span class="text-xs text-[#6f7e75] truncate max-w-xs font-medium">' . e($chap['title']) . '</span>' : '') . '
                                    </div>
                                    <div class="mt-1 flex items-center gap-3 text-[11px] text-[#84948a]">
                                        <span>' . e($chap['group']) . '</span>
                                        <span>•</span>
                                        <span>' . e($chap['publishAt']) . '</span>
                                    </div>
                                </div>
                                <a href="' . $readUrl . '" ' . $target . ' class="inline-flex items-center gap-1 rounded-xl bg-[#0f766e] px-4 py-1.5 text-xs font-bold uppercase tracking-wider text-white shadow-sm transition hover:bg-[#115e59]">
                                    <span>' . ($isExt ? 'Buka Eksternal' : 'Baca') . '</span>
                                    <span class="material-symbols-rounded text-sm">' . ($isExt ? 'open_in_new' : 'arrow_forward') . '</span>
                                </a>
                            </div>';
                        }
                        return $html;
                    };
                @endphp

                <!-- Indonesian Tab Content -->
                <div x-show="activeTab === 'id'" class="space-y-2">
                    {!! $renderChapters($indoChapters) !!}
                </div>

                <!-- English Tab Content -->
                <div x-show="activeTab === 'en'" class="space-y-2">
                    {!! $renderChapters($enChapters) !!}
                </div>

                <!-- All Tab Content -->
                <div x-show="activeTab === 'all'" class="space-y-2">
                    {!! $renderChapters($allChapters) !!}
                </div>
            </div>
        </section>

    </div>
</x-manga-layout>
