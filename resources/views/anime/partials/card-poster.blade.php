@php
    $meta = array_filter([
        $anime['year'] ?? null,
        !empty($anime['episodes']) ? $anime['episodes'] . ' EP' : null,
    ]);
@endphp

<a href="{{ route('anime.show', ['id' => $anime['id']]) }}"
   class="card-cv group flex min-w-0 flex-col gap-2">
    <div class="relative aspect-[2/3] w-full overflow-hidden rounded-xl border border-[#dcd2bd] bg-[#eaddc2] shadow-sm transition duration-300 group-hover:-translate-y-1 group-hover:border-[#0f766e]/60 group-hover:shadow-lg">
        <img src="{{ $anime['coverUrl'] }}" alt="{{ $anime['title'] }}" loading="lazy" decoding="async" referrerpolicy="no-referrer"
             class="h-full w-full object-cover object-top transition duration-500 group-hover:scale-105" />

        <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-[#172a37]/75 via-transparent to-transparent"></div>

        <span class="absolute left-2 top-2 rounded-full bg-[#0f766e]/95 px-2 py-0.5 font-mono text-[9px] font-semibold uppercase tracking-wide text-white shadow-sm">
            {{ $anime['format'] }}
        </span>

        @if($anime['score'])
            <span class="absolute right-2 top-2 inline-flex items-center gap-0.5 rounded-full bg-black/70 px-1.5 py-0.5 font-mono text-[10px] font-bold text-white">
                <span class="material-symbols-rounded text-[11px] text-[#fbbf24]">star</span>{{ $anime['score'] }}
            </span>
        @endif

        <span class="absolute bottom-2 left-2 rounded-full bg-[#f97316] px-2 py-0.5 font-mono text-[9px] font-bold uppercase tracking-wide text-white opacity-0 transition group-hover:opacity-100">
            Play
        </span>
    </div>

    <div class="min-w-0">
        <h3 class="line-clamp-2 min-h-[2.4rem] text-xs font-semibold leading-snug text-[#172a37] transition-colors group-hover:text-[#0f766e] sm:text-[13px]"
            title="{{ $anime['title'] }}">{{ $anime['title'] }}</h3>
        @if($meta)
            <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px] font-medium text-[#7b8a82]">
                @foreach($meta as $part)
                    <span>{{ $part }}</span>
                @endforeach
            </div>
        @endif
    </div>
</a>
