<a href="{{ route('anime.show', ['id' => $anime['id']]) }}"
   class="group block overflow-hidden rounded-3xl border border-[#e0d9c8] bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-[0_18px_40px_-18px_rgba(23,42,55,0.45)]">
    <div class="relative aspect-video overflow-hidden bg-[#172a37]">
        <img src="{{ $anime['bannerUrl'] ?: $anime['coverUrl'] }}" alt="{{ $anime['title'] }}" loading="lazy" decoding="async"
             class="h-full w-full object-cover transition duration-500 group-hover:scale-105" />
        <div class="absolute inset-0 bg-gradient-to-t from-black/55 via-transparent to-transparent"></div>
        <span class="absolute left-3 top-3 rounded-full bg-black/55 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-white backdrop-blur-sm">{{ $anime['format'] }}</span>
        @if($anime['score'])
            <span class="absolute right-3 top-3 inline-flex items-center gap-1 rounded-full bg-black/55 px-2.5 py-0.5 text-[10px] font-bold text-white backdrop-blur-sm">
                <span class="material-symbols-rounded text-xs text-[#fbbf24]">star</span>{{ $anime['score'] }}
            </span>
        @endif
        <span class="absolute bottom-3 right-3 flex h-10 w-10 items-center justify-center rounded-full bg-[#f97316] text-white opacity-0 shadow-lg transition group-hover:opacity-100">
            <span class="material-symbols-rounded">play_arrow</span>
        </span>
        <span class="absolute bottom-3 left-3 rounded-full bg-[#0f766e] px-2.5 py-0.5 text-[10px] font-bold text-white">{{ $anime['status'] }}</span>
    </div>
    <div class="space-y-1.5 p-4">
        <h3 class="truncate text-sm font-bold text-[#172a37] transition-colors group-hover:text-[#0f766e]" style="font-family: 'Space Grotesk', sans-serif;">{{ $anime['title'] }}</h3>
        <div class="flex items-center gap-2 text-[11px] font-semibold text-[#84948a]">
            @if($anime['year'])<span>{{ $anime['year'] }}</span><span>&middot;</span>@endif
            @if($anime['episodes'])<span>{{ $anime['episodes'] }} Episode</span><span>&middot;</span>@endif
            @if($anime['duration'])<span>{{ $anime['duration'] }} mnt/ep</span>@endif
        </div>
        @if(!empty($anime['genres']))
            <div class="flex flex-wrap gap-1 pt-0.5">
                @foreach(array_slice($anime['genres'], 0, 3) as $genre)
                    <span class="rounded-full bg-[#f6f1e8] px-2 py-0.5 text-[10px] font-semibold text-[#6f7e75]">{{ $genre }}</span>
                @endforeach
            </div>
        @endif
    </div>
</a>
