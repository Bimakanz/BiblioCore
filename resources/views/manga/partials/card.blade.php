@php
    $rank = $rank ?? null;
    $format = strtoupper((string) ($item['format'] ?? 'MANGA'));
    $isNovel = $format === 'NOVEL';
    $status = $item['status'] ?? 'Ongoing';
@endphp

<a href="{{ route('manga.show', $item['id']) }}"
   @mouseenter.once="prefetchShow('{{ route('manga.show', $item['id']) }}')"
   @touchstart.passive.once="prefetchShow('{{ route('manga.show', $item['id']) }}')"
   class="card-cv group flex min-w-0 flex-col gap-2">
    <div class="relative aspect-[2/3] w-full overflow-hidden rounded-xl border border-[#dcd2bd] bg-[#eaddc2] shadow-sm transition duration-300 group-hover:-translate-y-1 group-hover:border-[#0f766e]/60 group-hover:shadow-lg">
        @if(!empty($item['coverUrl']))
            <img src="{{ $item['coverUrl'] }}" alt="{{ $item['title'] }}" loading="lazy" decoding="async" referrerpolicy="no-referrer"
                 class="h-full w-full object-cover object-top transition duration-500 group-hover:scale-105"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';" />
            <div class="hidden h-full w-full flex-col items-center justify-center bg-gradient-to-br from-[#164e63] via-[#0f766e] to-[#134e4a] p-3 text-center text-white">
                <span class="material-symbols-rounded mb-1 text-3xl text-[#fde68a]">auto_stories</span>
                <span class="line-clamp-3 text-[11px] font-bold leading-snug">{{ $item['title'] }}</span>
            </div>
        @else
            <div class="flex h-full w-full flex-col items-center justify-center bg-gradient-to-br from-[#164e63] via-[#0f766e] to-[#134e4a] p-3 text-center text-white">
                <span class="material-symbols-rounded mb-1 text-3xl text-[#fde68a]">auto_stories</span>
                <span class="line-clamp-3 text-[11px] font-bold leading-snug">{{ $item['title'] }}</span>
            </div>
        @endif

        <span class="absolute left-2 top-2 rounded-full bg-[#0f766e]/95 px-2 py-0.5 font-mono text-[9px] font-semibold uppercase tracking-wide text-white shadow-sm">
            {{ $status }}
        </span>

        @if($isNovel)
            <span class="absolute bottom-2 left-2 rounded-full bg-amber-500/95 px-2 py-0.5 font-mono text-[9px] font-semibold uppercase tracking-wide text-white shadow-sm">
                Novel
            </span>
        @endif

        @if($rank)
            <span class="absolute bottom-1.5 right-2 font-mono text-2xl font-semibold text-white/90 drop-shadow-[0_1px_2px_rgba(0,0,0,0.7)]">
                {{ str_pad((string) $rank, 2, '0', STR_PAD_LEFT) }}
            </span>
        @endif

        <button type="button"
                @click.prevent.stop="toggleFav({ id: '{{ $item['id'] }}', title: '{{ addslashes($item['title']) }}', coverUrl: '{{ $item['coverUrl'] }}', status: '{{ addslashes($status) }}' })"
                class="absolute right-2 top-2 z-10 grid h-8 w-8 place-items-center rounded-full transition"
                :class="isFav('{{ $item['id'] }}') ? 'bg-[#f97316] text-white shadow-md' : 'bg-[#0f172a]/70 text-white/95 hover:bg-[#0f172a]/85'"
                aria-label="Save {{ $item['title'] }} to favorites">
            <span class="material-symbols-rounded text-[17px]">bookmark</span>
        </button>
    </div>

    <div class="min-w-0">
        <h3 class="line-clamp-2 text-xs font-semibold leading-snug text-[#172a37] transition-colors group-hover:text-[#0f766e] sm:text-[13px]"
            title="{{ $item['title'] }}">{{ $item['title'] }}</h3>
        <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] font-medium text-[#7b8a82]">
            @if(!empty($item['year']))
                <span>{{ $item['year'] }}</span>
            @endif
            @if(!empty($item['tags']))
                <span class="truncate rounded-md bg-[#ede6d6] px-1.5 py-0.5 text-[10px] font-semibold text-[#5f6b63]">{{ $item['tags'][0] }}</span>
            @endif
            <span>{{ $isNovel ? 'Novel' : 'Manga' }}</span>
        </div>
    </div>
</a>
