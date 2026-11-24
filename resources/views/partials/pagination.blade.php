@php
    $page = max(1, (int) ($page ?? 1));
    $totalPages = max(1, (int) ($totalPages ?? 1));
    $routeName = $routeName ?? 'manga.index';
    $baseQuery = request()->except('page');

    // Build smart page list: 1, ..., window around current, ..., totalPages
    $pages = [];
    if ($totalPages <= 7) {
        $pages = range(1, $totalPages);
    } else {
        $pages[] = 1;
        $start = max(2, $page - 1);
        $end = min($totalPages - 1, $page + 1);

        if ($page <= 3) {
            $start = 2;
            $end = 4;
        } elseif ($page >= $totalPages - 2) {
            $start = $totalPages - 3;
            $end = $totalPages - 1;
        }

        if ($start > 2) {
            $pages[] = '...';
        }
        for ($p = $start; $p <= $end; $p++) {
            $pages[] = $p;
        }
        if ($end < $totalPages - 1) {
            $pages[] = '...';
        }
        $pages[] = $totalPages;
    }
@endphp

@if($totalPages > 1)
    <nav aria-label="Pagination" class="flex flex-col items-center justify-center gap-3 pt-3 sm:flex-row sm:flex-wrap">
        <div class="flex flex-wrap items-center justify-center gap-1.5">
            @if($page > 1)
                <a href="{{ route($routeName, array_merge($baseQuery, ['page' => $page - 1])) }}"
                   class="inline-flex h-9 items-center gap-1 rounded-xl border border-[#dcd2bd] bg-white px-3 text-xs font-bold text-[#172a37] shadow-sm transition hover:border-[#0f766e]/50 hover:text-[#0f766e]">
                    <span class="material-symbols-rounded text-base">chevron_left</span>
                    <span class="hidden sm:inline">Prev</span>
                </a>
            @else
                <span class="inline-flex h-9 cursor-not-allowed items-center gap-1 rounded-xl border border-[#e6decb] bg-white/50 px-3 text-xs font-bold text-[#b0bab4]">
                    <span class="material-symbols-rounded text-base">chevron_left</span>
                    <span class="hidden sm:inline">Prev</span>
                </span>
            @endif

            @foreach($pages as $p)
                @if($p === '...')
                    <span class="inline-flex h-9 w-7 items-center justify-center text-xs font-bold text-[#84948a]">...</span>
                @elseif($p === $page)
                    <span aria-current="page"
                          class="inline-flex h-9 min-w-[36px] items-center justify-center rounded-xl bg-[#0f766e] px-2.5 text-xs font-bold text-white shadow-sm tabular-nums">
                        {{ $p }}
                    </span>
                @else
                    <a href="{{ route($routeName, array_merge($baseQuery, ['page' => $p])) }}"
                       class="inline-flex h-9 min-w-[36px] items-center justify-center rounded-xl border border-[#dcd2bd] bg-white px-2.5 text-xs font-bold text-[#37474f] transition hover:border-[#0f766e]/50 hover:text-[#0f766e] tabular-nums">
                        {{ $p }}
                    </a>
                @endif
            @endforeach

            @if($page < $totalPages)
                <a href="{{ route($routeName, array_merge($baseQuery, ['page' => $page + 1])) }}"
                   class="inline-flex h-9 items-center gap-1 rounded-xl bg-[#0f766e] px-3 text-xs font-bold text-white shadow-sm transition hover:bg-[#115e59]">
                    <span class="hidden sm:inline">Next</span>
                    <span class="material-symbols-rounded text-base">chevron_right</span>
                </a>
            @else
                <span class="inline-flex h-9 cursor-not-allowed items-center gap-1 rounded-xl border border-[#e6decb] bg-white/50 px-3 text-xs font-bold text-[#b0bab4]">
                    <span class="hidden sm:inline">Next</span>
                    <span class="material-symbols-rounded text-base">chevron_right</span>
                </span>
            @endif
        </div>

        <form action="{{ route($routeName) }}" method="GET"
              class="inline-flex items-center gap-1.5 rounded-xl border border-[#dcd2bd] bg-white/85 px-2.5 py-1 shadow-sm">
            @foreach($baseQuery as $k => $v)
                @if(is_scalar($v) && $v !== '')
                    <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                @endif
            @endforeach
            <label for="jumpPage-{{ $routeName }}" class="text-[11px] font-semibold text-[#6f7e75]">Go to</label>
            <input id="jumpPage-{{ $routeName }}"
                   type="number"
                   name="page"
                   min="1"
                   max="{{ $totalPages }}"
                   placeholder="{{ $page }}"
                   inputmode="numeric"
                   class="h-7 w-16 rounded-lg border border-[#dcd2bd] bg-[#fbf9f4] px-2 text-center text-xs font-bold text-[#172a37] tabular-nums focus:border-[#0f766e] focus:bg-white focus:outline-none focus:ring-1 focus:ring-[#0f766e]/20" />
            <button type="submit"
                    class="inline-flex h-7 items-center rounded-lg bg-[#172a37] px-2.5 text-[11px] font-bold text-white transition hover:bg-[#0f766e]">
                Go
            </button>
        </form>
    </nav>
@endif
