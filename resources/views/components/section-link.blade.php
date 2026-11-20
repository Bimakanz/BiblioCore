@props(['href' => null])

@php
    // Shared header action: a quiet outlined pill that fills on hover.
    // Renders an <a> when a link is given, otherwise a <button> so it can
    // drive Alpine handlers (e.g. opening the history modal).
    $classes = 'group inline-flex shrink-0 items-center gap-1.5 rounded-full border border-[#dcd2bd] bg-white/70 px-3.5 py-1.5 text-xs font-bold text-[#0f766e] transition duration-200 hover:border-[#0f766e]/35 hover:bg-[#0f766e] hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-[#0f766e]/40 focus-visible:ring-offset-2 focus-visible:ring-offset-[#f6f1e8]';
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
        <span class="material-symbols-rounded text-[15px] leading-none transition-transform duration-200 group-hover:translate-x-0.5">arrow_forward</span>
    </a>
@else
    <button type="button" {{ $attributes->merge(['class' => $classes]) }}>
        {{ $slot }}
        <span class="material-symbols-rounded text-[15px] leading-none transition-transform duration-200 group-hover:translate-x-0.5">arrow_forward</span>
    </button>
@endif
