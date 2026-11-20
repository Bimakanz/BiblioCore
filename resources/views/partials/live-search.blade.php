{{--
    Shared live-search bar.

    $variant = 'topbar' | 'mobile'

    Uses the layout variables:
    $homeRoute, $liveSearchEndpoint, $detailBase, $searchAllBase, $searchPlaceholder.
--}}
@php
    $variant = $variant ?? 'topbar';
    $initialQuery = (string) request('q', '');
    $queryJson = json_encode($initialQuery, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    $inputId = $variant === 'mobile' ? 'liveSearchMobile' : 'liveSearchDesktop';
@endphp

<div class="relative w-full {{ $variant === 'topbar' ? 'max-w-[540px]' : '' }}"
     x-data="{
        query: {{ $queryJson }},
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
            } catch (e) {
                this.results = [];
            } finally {
                this.loading = false;
            }
        }
     }"
     @click.away="open = false">
    <form action="{{ $homeRoute }}" method="GET" class="w-full" role="search">
        @if(request('category'))
            <input type="hidden" name="category" value="{{ request('category') }}">
        @endif
        <div class="relative w-full">
            <span class="material-symbols-rounded pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[19px] text-[#84948a]">search</span>
            <input type="text"
                   id="{{ $inputId }}"
                   name="q"
                   x-model="query"
                   @input.debounce.300ms="search()"
                   @focus="if (results.length > 0) open = true"
                   @keydown.escape="open = false"
                   placeholder="{{ $searchPlaceholder }}"
                   autocomplete="off"
                   aria-label="{{ $searchPlaceholder }}"
                   class="h-10 w-full rounded-xl border border-[#dcd2bd] bg-white/80 pl-10 pr-10 text-xs font-medium text-[#172a37] placeholder:text-[#9aa29a] transition focus:border-[#0f766e] focus:bg-white focus:outline-none focus:ring-2 focus:ring-[#0f766e]/15 sm:text-sm" />

            <span class="absolute right-3.5 top-1/2 -translate-y-1/2" x-show="loading" x-cloak>
                <span class="block h-4 w-4 animate-spin rounded-full border-2 border-[#0f766e] border-t-transparent"></span>
            </span>
        </div>
    </form>

    <div x-show="open && results.length > 0"
         x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 -translate-y-1"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="absolute left-0 right-0 top-full z-50 mt-2 max-h-[420px] space-y-1 overflow-y-auto rounded-2xl border border-[#dcd2bd] bg-white/95 p-2 shadow-2xl backdrop-blur-xl custom-scroll">
        <template x-for="item in results" :key="item.id">
            <a :href="'{{ $detailBase }}' + item.id"
               class="group flex items-center gap-3 rounded-xl p-2 transition hover:bg-[#f6ecda]/60">
                <img :src="item.coverUrl || 'https://via.placeholder.com/60x80?text=No+Cover'"
                     alt=""
                     class="h-14 w-10 flex-shrink-0 rounded-lg object-cover shadow-sm" />
                <div class="min-w-0 flex-1">
                    <h4 class="truncate text-xs font-bold text-[#172a37] transition-colors group-hover:text-[#0f766e] sm:text-sm" x-text="item.title"></h4>
                    <div class="mt-1 flex items-center gap-1.5">
                        <span class="rounded bg-[#0f766e]/10 px-1.5 py-0.5 font-mono text-[9px] font-semibold uppercase text-[#0f766e]" x-text="item.status || 'Ongoing'"></span>
                        <span x-show="item.format && item.format.toLowerCase() === 'novel'"
                              class="rounded bg-amber-500/10 px-1.5 py-0.5 font-mono text-[9px] font-semibold uppercase text-amber-700">Novel</span>
                        <span x-show="item.year" class="font-mono text-[10px] text-[#84948a]" x-text="item.year"></span>
                    </div>
                </div>
                <span class="material-symbols-rounded text-base text-[#84948a] transition group-hover:text-[#0f766e]">chevron_right</span>
            </a>
        </template>

        <a :href="'{{ $searchAllBase }}' + encodeURIComponent(query)"
           class="mt-1 block w-full rounded-xl border-t border-[#e0d9c8] py-2 text-center text-xs font-bold text-[#0f766e] transition hover:bg-[#0f766e]/10">
            View all results &rarr;
        </a>
    </div>
</div>
