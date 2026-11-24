<x-manga-layout title="{{ $anime['title'] }} Episode {{ $episodeNumber }} — BiblioAnime">
    <div class="space-y-4">

        {{-- Breadcrumb --}}
        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-[#84948a]">
            <a href="{{ route('anime.index') }}" class="hover:text-[#0f766e]">Anime</a>
            <span class="material-symbols-rounded text-sm">chevron_right</span>
            <a href="{{ route('anime.show', ['id' => $anime['id']]) }}" class="hover:text-[#0f766e]">{{ $anime['title'] }}</a>
            <span class="material-symbols-rounded text-sm">chevron_right</span>
            <span class="text-[#172a37]">Episode {{ $episodeNumber }}</span>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
            {{-- ===== PLAYER COLUMN ===== --}}
            <div class="min-w-0 space-y-4">
                <div id="playerWrap" class="relative overflow-hidden rounded-2xl sm:rounded-[1.75rem] bg-black shadow-[0_20px_50px_-24px_rgba(0,0,0,0.65)] group">
                    <video id="player" class="aspect-video w-full bg-black" crossorigin="anonymous" playsinline controls
                           poster="{{ $anime['bannerUrl'] ?: $anime['coverUrl'] }}"></video>

                    <div id="playerLoading" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/60 text-white backdrop-blur-[2px] z-40">
                        <div class="relative h-14 w-14 overflow-hidden rounded-2xl">
                            <img src="{{ asset('images/loading/loading-1.webp') }}" alt="" fetchpriority="high" class="loader-img loader-img-1">
                            <img src="{{ asset('images/loading/loading-2.webp') }}" alt="" fetchpriority="high" class="loader-img loader-img-2">
                        </div>
                        <p class="text-sm font-semibold">Loading wait a damn minute wontcha?</p>
                        <p class="text-xs text-white/60">Direct source, no ads</p>
                    </div>

                    <div id="playerError" class="absolute inset-0 hidden flex-col items-center justify-center gap-3 bg-black/85 p-6 text-center text-white z-40">
                        <span class="material-symbols-rounded text-4xl text-[#f97316]">error</span>
                        <p id="playerErrorText" class="max-w-md text-sm font-medium">Stream is not available for this episode.</p>
                        <button type="button" id="retryBtn" class="rounded-full bg-[#f97316] px-5 py-2 text-xs font-bold text-white transition hover:bg-[#ea580c]">Try Again</button>
                        <button type="button" id="embedBtn" class="rounded-full border border-white/25 bg-white/10 px-5 py-2 text-xs font-bold text-white transition hover:bg-white/20">Try Backup Server</button>
                        <a id="backToAnimeBtn" href="{{ route('anime.show', ['id' => $anime['id']]) }}"
                           class="hidden rounded-full bg-[#0f766e] px-5 py-2 text-xs font-bold text-white transition hover:bg-[#115e59]">
                            Back to anime details
                        </a>
                    </div>


                    <div id="skipBtns" class="absolute bottom-32 right-4 z-30 flex flex-col items-end gap-2">
                        <button type="button" id="skipIntroBtn" class="hidden rounded-full bg-[#0f766e] px-3.5 py-1.5 text-xs font-bold text-white shadow-lg transition hover:bg-[#115e59]">
                            Skip Opening &raquo;
                        </button>
                        <button type="button" id="skipOutroBtn" class="hidden rounded-full bg-[#0f766e] px-3.5 py-1.5 text-xs font-bold text-white shadow-lg transition hover:bg-[#115e59]">
                            Skip Outro &raquo;
                        </button>
                    </div>

                    <a id="nextEpOverlay" href="#" class="absolute bottom-32 left-4 hidden items-center gap-2 rounded-full bg-[#f97316] px-4 py-2 text-xs font-bold text-white shadow-lg transition hover:bg-[#ea580c] z-30">
                        Next Episode <span class="material-symbols-rounded text-base">skip_next</span>
                    </a>
                </div>

                {{-- Episode navigation: dedicated row under the player --}}
                <div class="flex items-center justify-between gap-3">
                    <a id="prevEpBtn" href="#" class="hidden items-center gap-1.5 rounded-full border border-[#dcd2bd] bg-white px-4 py-2 text-xs font-bold text-[#172a37] shadow-sm transition hover:border-[#0f766e]/60 hover:text-[#0f766e]">
                        <span class="material-symbols-rounded text-base">skip_previous</span> Previous Episode
                    </a>
                    <a id="nextEpBtn" href="#" class="hidden items-center gap-1.5 rounded-full bg-[#0f766e] px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-[#115e59]">
                        Next Episode <span class="material-symbols-rounded text-base">skip_next</span>
                    </a>
                </div>

                <p id="audioNotice" class="hidden rounded-2xl border border-[#f5c99b] bg-[#fff7ed] px-4 py-2 text-xs font-semibold text-[#9a3412]">
                    The dub for this episode is not available yet — playing the sub version.
                </p>

                <p id="versionNotice" class="hidden rounded-2xl border border-[#bae6fd] bg-[#f0f9ff] px-4 py-2 text-xs font-semibold text-[#075985]">
                    The burned-in copy is not available for this episode — playing the standard source instead.
                </p>

                <div id="embedWarning" class="hidden items-start gap-3 rounded-2xl border border-[#f59e0b]/45 bg-[#fffbeb] px-4 py-3 text-xs text-[#92400e] shadow-sm">
                    <span class="material-symbols-rounded mt-0.5 flex-shrink-0 text-[19px] text-[#d97706]">warning</span>
                    <div class="min-w-0 flex-1 leading-relaxed">
                        <p class="font-bold text-[#78350f]">Backup Embed Server Active (May Contain Pop-ups / Redirects)</p>
                        <p class="mt-0.5 text-[#92400e]">
                            Episode ini memakai player pihak ketiga yang dapat membuka tab iklan atau redirect ke situs/aplikasi lain saat diklik. Disarankan menggunakan <strong>AdBlocker</strong> atau <strong>Brave Browser</strong> di HP agar lebih aman.
                        </p>
                    </div>
                    <button type="button" id="dismissEmbedWarning" class="flex-shrink-0 rounded-lg p-1 text-[#b45309] transition hover:bg-[#fde68a]/60" aria-label="Dismiss warning">
                        <span class="material-symbols-rounded text-base">close</span>
                    </button>
                </div>

                {{-- Control bar (compact single-row toolbar) --}}
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-2xl border border-[#e0d9c8] bg-white px-3 py-2 shadow-sm">
                    <div id="audioWrap" class="flex items-center gap-1.5">
                        <span class="text-[10px] font-bold uppercase tracking-wide text-[#84948a]">Audio</span>
                        <div id="audioToggle" class="flex items-center gap-0.5 rounded-full border border-[#dcd2bd] bg-[#fbf9f4] p-0.5">
                            <button type="button" id="audioSubBtn" data-audio="sub"
                                    class="rounded-full px-2.5 py-0.5 text-[11px] font-bold transition bg-[#0f766e] text-white shadow-sm">Sub</button>
                            <button type="button" id="audioDubBtn" data-audio="dub"
                                    class="rounded-full px-2.5 py-0.5 text-[11px] font-bold text-[#4c5b54] transition hover:text-[#0f766e] disabled:cursor-not-allowed disabled:opacity-40">Dub</button>
                        </div>
                    </div>

                    <span class="hidden h-4 w-px bg-[#e0d9c8] sm:block"></span>

                    <div class="flex items-center gap-1.5">
                        <span class="text-[10px] font-bold uppercase tracking-wide text-[#84948a]">Quality</span>
                        <div class="relative">
                            <button type="button" id="qualityBtn" class="pill-btn"><span id="qualityBtnLabel">Auto</span><span class="material-symbols-rounded text-[14px] opacity-60">expand_more</span></button>
                            <div id="qualityMenu" class="pill-menu hidden">
                                <button type="button" class="pill-option is-active" data-value="-1">Auto</button>
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center gap-1.5">
                        <span class="text-[10px] font-bold uppercase tracking-wide text-[#84948a]">Sub</span>
                        <div class="relative">
                            <button type="button" id="subtitleBtn" class="pill-btn"><span id="subtitleBtnLabel">Off</span><span class="material-symbols-rounded text-[14px] opacity-60">expand_more</span></button>
                            <div id="subtitleMenu" class="pill-menu hidden">
                                <button type="button" class="pill-option is-active" data-value="-1">Off</button>
                            </div>
                        </div>
                    </div>

                    <span class="hidden h-4 w-px bg-[#e0d9c8] sm:block"></span>

                    <div class="flex items-center gap-1.5" title="Subtitle sync: − shows earlier, + shows later. Tap value to reset.">
                        <span class="text-[10px] font-bold uppercase tracking-wide text-[#84948a]">Sync</span>
                        <div class="inline-flex items-center rounded-full border border-[#dcd2bd] bg-[#fbf9f4] p-0.5">
                            <button type="button" id="subMinus"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-bold text-[#172a37] transition hover:bg-white hover:text-[#0f766e]"
                                    title="Show subtitles 0.5s earlier — hold Shift for 0.1s">&minus;0.5s</button>
                            <button type="button" id="subOffsetLabel"
                                    class="min-w-[34px] rounded-full px-1.5 py-0.5 text-center text-[11px] font-bold text-[#0f766e] tabular-nums transition hover:bg-[#0f766e]/10"
                                    title="Subtitle offset — tap to reset to 0s">0s</button>
                            <button type="button" id="subPlus"
                                    class="rounded-full px-2 py-0.5 text-[11px] font-bold text-[#172a37] transition hover:bg-white hover:text-[#0f766e]"
                                    title="Show subtitles 0.5s later — hold Shift for 0.1s">+0.5s</button>
                        </div>
                    </div>
                </div>

                <div class="rounded-2xl sm:rounded-3xl border border-[#e0d9c8] bg-white p-4 sm:p-5 shadow-sm">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-lg sm:text-xl font-bold text-[#172a37]">{{ $anime['title'] }}</h1>
                        <span class="rounded-full bg-[#0f766e]/10 px-2.5 py-0.5 text-xs font-bold text-[#0f766e]">Episode {{ $episodeNumber }}</span>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs font-semibold text-[#6f7e75]">
                        <span class="rounded-md bg-[#f6f1e8] px-2 py-0.5">{{ $anime['format'] }}</span>
                        <span class="rounded-md bg-[#f6f1e8] px-2 py-0.5">{{ $anime['status'] }}</span>
                        @if($anime['year'])<span class="rounded-md bg-[#f6f1e8] px-2 py-0.5">{{ $anime['year'] }}</span>@endif
                        @if($anime['score'])<span class="rounded-md bg-[#fef3c7] px-2 py-0.5 text-[#92400e]">Score {{ $anime['score'] }}/10</span>@endif
                    </div>
                    <p class="mt-3 text-sm leading-relaxed text-[#4c5b54] line-clamp-3">{{ $anime['description'] }}</p>
                </div>
            </div>

            {{-- ===== RIGHT COLUMN: episodes + recommendations ===== --}}
            <div class="space-y-5 lg:sticky lg:top-[76px] lg:max-h-[calc(100dvh-5.75rem)] lg:overflow-y-auto lg:pr-1 lg:no-scrollbar">
            <aside class="h-fit overflow-hidden rounded-2xl border border-[#e0d9c8] bg-white shadow-sm"
                   x-data="{ epOpen: false }">
                <button type="button" @click="epOpen = !epOpen"
                        class="flex w-full items-center justify-between gap-2 border-b border-[#e0d9c8] px-4 py-3 text-left lg:cursor-default">
                    <span class="font-display text-sm font-bold text-[#172a37]">Episode List</span>
                    <span class="material-symbols-rounded text-base text-[#84948a] transition-transform lg:hidden"
                          :class="epOpen ? 'rotate-180' : ''">expand_more</span>
                </button>
                <div class="p-3" :class="epOpen ? 'block' : 'hidden lg:block'">
                    <div id="epList" class="custom-scroll max-h-[560px] space-y-1.5 overflow-y-auto pr-1">
                        <div class="space-y-1.5">
                            @for($i = 0; $i < 8; $i++)
                                <div class="h-10 animate-pulse rounded-xl bg-[#f1ebd8]"></div>
                            @endfor
                        </div>
                    </div>
                </div>
            </aside>

            <aside class="rounded-2xl border border-[#e0d9c8] bg-white p-4 shadow-sm h-fit">
                <h2 class="mb-1 font-display text-sm font-bold text-[#172a37]">You Might Like</h2>
                <p class="mb-3 text-[11px] font-medium text-[#84948a]">Based on genres similar to what you are watching</p>
                <div id="recoList" class="space-y-2.5">
                    @for($i = 0; $i < 4; $i++)
                        <div class="flex gap-3">
                            <div class="aspect-video w-28 flex-shrink-0 animate-pulse rounded-xl bg-[#f1ebd8]"></div>
                            <div class="flex-1 space-y-1.5 py-0.5">
                                <div class="h-3 w-4/5 animate-pulse rounded bg-[#f1ebd8]"></div>
                                <div class="h-3 w-2/5 animate-pulse rounded bg-[#f1ebd8]"></div>
                            </div>
                        </div>
                    @endfor
                </div>
            </aside>
            </div>
        </div>
    </div>

    <style>
        #playerWrap:fullscreen { border-radius: 0; }
        /* Custom two-image crossfade loader (watch page only) */
        .loader-img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: 0; animation: loaderCross 3.2s ease-in-out infinite; }
        .loader-img-2 { animation-delay: -1.6s; }
        @keyframes loaderCross { 0% { opacity: 0.55; } 35% { opacity: 0.55; } 50% { opacity: 0; } 85% { opacity: 0; } 100% { opacity: 0.55; } }
        @media (prefers-reduced-motion: reduce) { .loader-img { animation: none; } .loader-img-1 { opacity: 0.55; } .loader-img-2 { opacity: 0; } }
        #playerWrap:fullscreen video { width: 100%; height: 100%; }
        /* Custom pill dropdowns (button + own menu panel, no native select) */
        .pill-btn { display: inline-flex; align-items: center; justify-content: center; gap: 4px; min-width: 68px; border-radius: 9999px; border: 1px solid #dcd2bd; background-color: #fbf9f4; padding: 4px 10px 4px 12px; font-size: 11px; font-weight: 700; color: #172a37; cursor: pointer; transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s; }
        .pill-btn:hover { border-color: rgba(15, 118, 110, 0.55); background-color: #ffffff; }
        .pill-btn:focus { outline: none; border-color: #0f766e; box-shadow: 0 0 0 3px rgba(15, 118, 110, 0.16); background-color: #ffffff; }
        .pill-btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .pill-menu { position: absolute; left: 0; top: calc(100% + 8px); z-index: 40; min-width: 180px; max-width: calc(100vw - 2rem); max-height: 264px; overflow-y: auto; border-radius: 18px; border: 1px solid #e0d9c8; background-color: #ffffff; padding: 6px; box-shadow: 0 22px 45px -18px rgba(23, 42, 55, 0.45); }
        .pill-option { display: flex; align-items: center; justify-content: space-between; gap: 8px; width: 100%; border-radius: 12px; padding: 8px 12px; font-size: 12px; font-weight: 600; color: #4c5b54; text-align: left; cursor: pointer; transition: background-color 0.15s, color 0.15s; }
        .pill-option:hover { background-color: #f6f1e8; color: #172a37; }
        .pill-option.is-active { background-color: #0f766e; color: #ffffff; }
        .pill-option.is-active::after { content: 'check'; font-family: 'Material Symbols Rounded'; font-size: 15px; line-height: 1; }
        @media (max-width: 640px) { .pill-menu-right { left: auto; right: 0; } }
        #playerWrap.hide-ui { cursor: none; }
        /* Idle state in fullscreen: also get the native control bar and our
           own overlays out of the picture, not just the cursor. */
        #playerWrap.hide-ui video::-webkit-media-controls-panel,
        #playerWrap.hide-ui video::-webkit-media-controls { opacity: 0; transition: opacity 0.3s; }
        #playerWrap.hide-ui #skipBtns,
        #playerWrap.hide-ui #nextEpOverlay,
        #playerWrap.hide-ui #embedFrame { opacity: 0; pointer-events: none; transition: opacity 0.3s; }

        /* Skip buttons glow */
        #skipBtns button { box-shadow: 0 0 15px rgba(15, 118, 110, 0.35); }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/hls.js@1.5.13/dist/hls.min.js"></script>
    <script>
    (function () {
        const ANIME = {
            id: @json($anime['id']),
            title: @json($anime['title']),
            coverUrl: @json($anime['coverUrl']),
            bannerUrl: @json($anime['bannerUrl']),
            // AniList stores the runtime in minutes. Embed players do not
            // expose their timeline, so this is the duration used for the
            // saved progress of embed-only sources.
            durationSec: {{ max(0, (int) ($anime['duration'] ?? 0)) * 60 }},
        };
        const EPISODE_ID = @json($episodeId);
        const EPISODE_NUMBER = {{ (int) $episodeNumber }};
        const STREAM_URL = @json($streamUrl);
        const EPISODES_URL = @json($episodesUrl);
        const SKIP_TIMES_URL = @json(route('anime.skip-times.json', ['id' => $anime['id']]));
        const URL_T = {{ (int) request()->query('t', 0) }};
        const HISTORY_KEY = 'biblioanime_history';
        // Subtitle sync is remembered per anime: every episode of a series
        // usually comes from the same release, so it carries the same
        // constant delay.
        const SUB_OFFSET_PREFIX = 'biblioanime_sub_offset_';

        const video = document.getElementById('player');
        const loadingEl = document.getElementById('playerLoading');
        const errorEl = document.getElementById('playerError');
        const errorText = document.getElementById('playerErrorText');
        const skipIntroBtn = document.getElementById('skipIntroBtn');
        const skipOutroBtn = document.getElementById('skipOutroBtn');
        const nextOverlay = document.getElementById('nextEpOverlay');
        let qualityPref = -1;
        try { qualityPref = parseInt(localStorage.getItem('biblioanime_quality') || '-1', 10); if (isNaN(qualityPref)) qualityPref = -1; } catch (e) {}
        let audioValue = @js($audio) === 'dub' ? 'dub' : 'sub';
        let dubAvailable = @js($dubAvailable);
        // Burned-in copies come from separate hard-subtitle sources, picked
        // with ?server=animeheaven|samehadaku. Empty means the normal chain.
        let hardsubServer = @js(in_array(request()->query('server'), ['animeheaven', 'samehadaku'], true) ? request()->query('server') : '');

        // Custom pill dropdowns: button + menu panel (no native <select>).
        function setupDropdown(btnId, menuId, labelId, onPick) {
            const btn = document.getElementById(btnId);
            const menu = document.getElementById(menuId);
            const label = document.getElementById(labelId);
            function setActive(value) {
                menu.querySelectorAll('.pill-option').forEach(function (opt) {
                    const active = opt.dataset.value === String(value);
                    opt.classList.toggle('is-active', active);
                    if (active && label) label.textContent = opt.textContent;
                });
            }
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                document.querySelectorAll('.pill-menu').forEach(function (m) { if (m !== menu) m.classList.add('hidden'); });
                const opening = menu.classList.contains('hidden');
                menu.classList.toggle('hidden');
                if (opening) {
                    menu.style.left = '0';
                    menu.style.right = 'auto';
                    const rect = menu.getBoundingClientRect();
                    if (rect.right > window.innerWidth - 12) {
                        menu.style.left = 'auto';
                        menu.style.right = '0';
                        const rect2 = menu.getBoundingClientRect();
                        if (rect2.left < 12) {
                            menu.style.right = 'auto';
                            menu.style.left = (12 - rect2.left) + 'px';
                        }
                    }
                }
            });
            document.addEventListener('click', function (e) {
                if (!menu.classList.contains('hidden') && !menu.contains(e.target)) menu.classList.add('hidden');
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') menu.classList.add('hidden');
            });
            menu.addEventListener('click', function (e) {
                const opt = e.target.closest('.pill-option');
                if (!opt) return;
                setActive(opt.dataset.value);
                menu.classList.add('hidden');
                onPick(opt.dataset.value);
            });
            return { setActive: setActive, menu: menu, btn: btn };
        }

        // Audio (sub/dub) replaces the old server picker: the resolver always
        // chooses the source automatically, the user only picks the track.
        const AUDIO_ON = ['bg-[#0f766e]', 'text-white', 'shadow-sm'];
        const AUDIO_OFF = ['text-[#4c5b54]', 'hover:text-[#0f766e]'];

        function paintAudioButtons() {
            const subBtn = document.getElementById('audioSubBtn');
            const dubBtn = document.getElementById('audioDubBtn');
            if (!subBtn || !dubBtn) return;
            const active = audioValue === 'dub' ? dubBtn : subBtn;
            const idle = audioValue === 'dub' ? subBtn : dubBtn;
            active.classList.remove.apply(active.classList, AUDIO_OFF);
            active.classList.add.apply(active.classList, AUDIO_ON);
            idle.classList.remove.apply(idle.classList, AUDIO_ON);
            idle.classList.add.apply(idle.classList, AUDIO_OFF);
            dubBtn.disabled = !dubAvailable && audioValue !== 'dub';
            dubBtn.title = dubBtn.disabled ? 'Dub is not available for this title' : '';
        }

        function pickAudio(next) {
            if (next === audioValue) return;
            if (next === 'dub' && !dubAvailable) return;
            audioValue = next;
            // Both burned-in libraries are sub-only, so a dub choice always
            // goes back to the standard chain.
            if (next === 'dub' && hardsubServer) {
                setHardsubServer('');
                const notice = document.getElementById('versionNotice');
                if (notice) notice.classList.add('hidden');
            }
            try { localStorage.setItem('biblioanime_audio', next); } catch (e) {}
            paintAudioButtons();
            goToAudio();
        }

        // Switching audio means switching episode ids (providers expose
        // separate sub/dub entries), so keep the number and reload the page
        // with the matching id.
        async function goToAudio() {
            try {
                const res = await fetch(EPISODES_URL + '?audio=' + encodeURIComponent(audioValue));
                const data = await res.json();
                const eps = data.episodes || [];
                const match = eps.find(function (ep) { return Number(ep.number) === Number(EPISODE_NUMBER); });
                if (!match) {
                    showError('Versi ' + (audioValue === 'dub' ? 'Dub' : 'Sub') + ' is not available for this episode.');
                    audioValue = audioValue === 'dub' ? 'sub' : 'dub';
                    paintAudioButtons();
                    return;
                }
                location.href = '/anime/' + ANIME.id + '/watch?ep=' + encodeURIComponent(match.id) + '&n=' + match.number + '&audio=' + audioValue + (hardsubServer ? '&server=' + encodeURIComponent(hardsubServer) : '');
            } catch (e) {
                showError('Failed to load the ' + (audioValue === 'dub' ? 'Dub' : 'Sub') + '.'); 
            }
        }

        const audioSubBtn = document.getElementById('audioSubBtn');
        const audioDubBtn = document.getElementById('audioDubBtn');
        if (audioSubBtn) audioSubBtn.addEventListener('click', function () { pickAudio('sub'); });
        if (audioDubBtn) audioDubBtn.addEventListener('click', function () { pickAudio('dub'); });
        paintAudioButtons();
        const qualityDropdown = setupDropdown('qualityBtn', 'qualityMenu', 'qualityBtnLabel', function (v) {
            qualityPref = parseInt(v, 10);
            if (isNaN(qualityPref)) qualityPref = -1;
            try { localStorage.setItem('biblioanime_quality', String(qualityPref)); } catch (e) {}
            if (hls) hls.currentLevel = qualityPref;
        });
        const subtitleDropdown = setupDropdown('subtitleBtn', 'subtitleMenu', 'subtitleBtnLabel', function (v) {
            applySubtitle(parseInt(v, 10));
        });

        function setHardsubServer(value) {
            hardsubServer = '';
        }

        function streamUrl() {
            return STREAM_URL;
        }

        function watchHref(epId, number, audio) {
            return '/anime/' + ANIME.id + '/watch?ep=' + encodeURIComponent(epId)
                + '&n=' + number + '&audio=' + encodeURIComponent(audio);
        }

        // The episode list is built once; keep its links in sync with the
        // current version so switching to a burned-in copy sticks.
        function refreshEpisodeLinks() {
            document.querySelectorAll('[data-ep-id]').forEach(function (a) {
                const audio = a.dataset.epAudio || audioValue;
                a.href = watchHref(a.dataset.epId, a.dataset.epN, audio);
            });
        }

        let hls = null;
        let intro = null;
        let outro = null;
        let skipTimesLoaded = false;
        let nextEpisodeUrl = null;
        let autoNextDone = false;
        let subOffset = readSubOffset();
        let lastSavedAt = 0;
        let resumeApplied = false;
        let resumeOverride = 0;
        // 'hls' (our own player, real timeline) or 'embed' (third-party
        // iframe, timeline not readable from JS). For embeds the elapsed
        // watch time is counted locally so history still gets seconds,
        // minutes and hours instead of being skipped entirely.
        let playMode = 'hls';
        let embedWatchSeconds = 0;
        let embedClock = null;
        // Guards the MP4 error fallback so one dead file never reloads twice.
        let mp4Failed = false;

        function readHistory() {
            try { return JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]'); } catch (e) { return []; }
        }

        function watchedSeconds() {
            return playMode === 'embed'
                ? Math.floor(embedWatchSeconds || 0)
                : Math.floor(video.currentTime || 0);
        }

        function watchedDuration() {
            if (playMode !== 'embed' && video.duration && !isNaN(video.duration) && video.duration > 0) {
                return Math.floor(video.duration);
            }
            return Math.floor(ANIME.durationSec || 0);
        }

        function saveProgress(finished) {
            const duration = watchedDuration();
            let position = finished ? duration : watchedSeconds();
            if (!position && !duration) {
                // Nothing meaningful yet, but still record the episode so
                // "last watched" appears the moment the page opens.
                position = 0;
            }

            const previous = readHistory().find(h => h.id === ANIME.id);
            // Never let an early page-load save rewind a real position:
            // storedResume() reads this same entry right before playback.
            if (!finished && previous && previous.episodeId === EPISODE_ID
                && Number(previous.positionSec) > position + 5
                && Number(previous.durationSec) > 0
                && Number(previous.positionSec) < Number(previous.durationSec) - 30) {
                position = Number(previous.positionSec);
            }

            const entry = {
                id: ANIME.id,
                title: ANIME.title,
                coverUrl: ANIME.coverUrl,
                bannerUrl: ANIME.bannerUrl,
                episodeId: EPISODE_ID,
                episodeNumber: EPISODE_NUMBER,
                positionSec: Math.max(0, Math.floor(position)),
                durationSec: Math.max(0, duration),
                finished: !!finished,
                updatedAt: Date.now(),
                watchedAt: new Date().toISOString(),
            };
            let list = readHistory().filter(h => h.id !== ANIME.id);
            list.unshift(entry);
            list = list.slice(0, 30);
            try { localStorage.setItem(HISTORY_KEY, JSON.stringify(list)); } catch (e) {}
        }

        // Embed pages never fire timeupdate on our <video>, so count the
        // seconds ourselves while the tab is actually visible. Persisting
        // every few seconds keeps the entry current even if the tab is
        // closed without a final event.
        function startEmbedClock() {
            stopEmbedClock();
            embedWatchSeconds = 0;
            embedClock = setInterval(function () {
                if (document.hidden) return;
                embedWatchSeconds++;
                if (embedWatchSeconds % 5 === 0) saveProgress(false);
            }, 1000);
        }

        function stopEmbedClock() {
            if (embedClock) {
                clearInterval(embedClock);
                embedClock = null;
            }
        }

        function storedResume() {
            if (resumeOverride > 0) return resumeOverride;
            if (URL_T > 0) return URL_T;
            const entry = readHistory().find(h => h.id === ANIME.id && h.episodeId === EPISODE_ID);
            if (entry && entry.positionSec > 10 && entry.durationSec && entry.positionSec < entry.durationSec - 30) {
                return Math.max(0, entry.positionSec - 5);
            }
            return 0;
        }

        function showError(msg) {
            loadingEl.style.display = 'none';
            errorText.textContent = msg || 'Stream is not available for this episode. Try another server.';
            errorEl.style.display = 'flex';
            const retry = document.getElementById('retryBtn');
            const backup = document.getElementById('embedBtn');
            const back = document.getElementById('backToAnimeBtn');
            if (retry) retry.classList.remove('hidden');
            if (backup) backup.classList.remove('hidden');
            if (back) back.classList.add('hidden');
        }

        // Terminal state: the backend confirmed NO provider carries this
        // title. Offering "try another server" here would just loop forever,
        // so send the visitor back to the detail page instead.
        function showUnavailable(msg) {
            loadingEl.style.display = 'none';
            errorText.textContent = msg || 'This title has no video source on any provider.';
            errorEl.style.display = 'flex';
            const retry = document.getElementById('retryBtn');
            const backup = document.getElementById('embedBtn');
            const back = document.getElementById('backToAnimeBtn');
            if (retry) retry.classList.add('hidden');
            if (backup) backup.classList.add('hidden');
            if (back) back.classList.remove('hidden');
        }

        function hideOverlays() {
            errorEl.style.display = 'none';
            loadingEl.style.display = 'flex';
        }

        function langCode(sub, idx) {
            const raw = (sub.lang || '').toLowerCase();
            if (raw.indexOf('indo') === 0 || raw === 'id' || raw === 'id-auto') return 'id';
            if (raw.indexOf('eng') === 0 || raw === 'en') return 'en';
            return raw.slice(0, 2) || ('s' + idx);
        }

        // Some providers mistag almost every subtitle as srclang "en" while
        // the human label still says what it really is ("Arabic", "Chinese",
        // "Thai", "Indonesian", ...). The label is far more trustworthy than
        // the srclang attribute, so the label is the ONLY source we trust
        // whenever it contains text. An unrecognised label is treated as
        // "unknown" instead of falling back to the provider tag, because that
        // tag is exactly what made a Chinese track look like English.
        function languageFromLabel(label) {
            const l = String(label || '').trim().toLowerCase();
            if (!l) return null;
            if (/^english\b|^eng$|^en$/.test(l)) return 'en';
            if (/^indonesian\b|^indonesia\b|^indo$|^id$/.test(l)) return 'id';
            if (/^arabic\b|^ara$|^ar$/.test(l)) return 'ar';
            if (/^chinese\b|^mandarin\b|^cantonese\b|^zho$|^chi$|^zh$/.test(l)) return 'zh';
            if (/^japanese\b|^jpn$|^jp$|^ja$/.test(l)) return 'ja';
            if (/^korean\b|^kor$|^ko$/.test(l)) return 'ko';
            if (/^thai\b|^tha$|^th$/.test(l)) return 'th';
            if (/^french\b|^fra$|^fre$|^fr$/.test(l)) return 'fr';
            if (/^german\b|^deu$|^ger$|^de$/.test(l)) return 'de';
            if (/^italian\b|^ita$|^it$/.test(l)) return 'it';
            if (/^portuguese\b|^por$|^pt$/.test(l)) return 'pt';
            if (/^russian\b|^rus$|^ru$/.test(l)) return 'ru';
            if (/^spanish\b|^spa$|^es$/.test(l)) return 'es';
            if (/^vietnamese\b|^vie$|^vi$/.test(l)) return 'vi';
            if (/^bengali\b|^ben$|^bn$/.test(l)) return 'bn';
            if (/^persian\b|^farsi\b|^fas$|^fa$/.test(l)) return 'fa';
            if (/^turkish\b|^tur$|^tr$/.test(l)) return 'tr';
            if (/^polish\b|^pol$|^pl$/.test(l)) return 'pl';
            if (/^dutch\b|^nld$|^dut$|^nl$/.test(l)) return 'nl';
            if (/^ukrainian\b|^ukr$|^uk$/.test(l)) return 'uk';
            if (/^romanian\b|^ron$|^rum$|^ro$/.test(l)) return 'ro';
            if (/^hungarian\b|^hun$|^hu$/.test(l)) return 'hu';
            if (/^czech\b|^ces$|^cze$|^cs$/.test(l)) return 'cs';
            if (/^greek\b|^ell$|^gre$|^el$/.test(l)) return 'el';
            if (/^hebrew\b|^heb$|^he$/.test(l)) return 'he';
            if (/^swedish\b|^swe$|^sv$/.test(l)) return 'sv';
            if (/^danish\b|^dan$|^da$/.test(l)) return 'da';
            if (/^finnish\b|^fin$|^fi$/.test(l)) return 'fi';
            if (/^norwegian\b|^nor$|^no$/.test(l)) return 'no';
            if (/^malay\b|^msa$|^may$|^ms$/.test(l)) return 'ms';
            if (/^filipino\b|^tagalog\b|^fil$|^tl$/.test(l)) return 'tl';
            if (/^hindi\b|^hin$|^hi$/.test(l)) return 'hi';
            if (/^tamil\b|^tam$|^ta$/.test(l)) return 'ta';
            if (/^telugu\b|^tel$|^te$/.test(l)) return 'te';
            if (/^urdu\b|^urd$|^ur$/.test(l)) return 'ur';
            return null;
        }

        function subtitleLanguage(sub, idx) {
            const label = String(sub.label || '').trim();
            // A label is present: trust it (or mark unknown). NEVER fall
            // back to the provider's srclang here, because providers lie
            // about it (Chinese/Arabic tracks tagged "en").
            if (label) return languageFromLabel(label) || 'unknown';
            return langCode(sub, idx);
        }

        let currentSubtitles = [];
        let currentSubIdx = -1;

        // English is THE default, unconditionally. There is no fallback to
        // Indonesian or any other language: when a provider ships no English
        // track the server adds an "English (Auto)" translated track, so an
        // English option always exists. If even that is impossible the
        // subtitles stay off instead of silently switching to Arabic, Thai,
        // Spanish, ... A language the viewer picked by hand still works for
        // the current episode, but the next page load starts at English.
        function preferredSubtitleIndex(subtitles) {
            let en = -1;
            subtitles.forEach((sub, i) => {
                if (en === -1 && subtitleLanguage(sub, i) === 'en') en = i;
            });
            return en;
        }

        function buildSubtitleOptions(subtitles) {
            video.querySelectorAll('track').forEach(t => t.remove());
            const menu = subtitleDropdown.menu;
            menu.innerHTML = '';
            const offBtn = document.createElement('button');
            offBtn.type = 'button';
            offBtn.className = 'pill-option';
            offBtn.dataset.value = '-1';
            offBtn.textContent = 'Off';
            menu.appendChild(offBtn);
            subtitles.forEach((sub, i) => {
                const track = document.createElement('track');
                track.kind = 'subtitles';
                track.label = sub.label;
                track.srclang = subtitleLanguage(sub, i);
                track.src = sub.url;
                // Keep the cue text above the floating control bar instead
                // of pinning it to the very bottom of the video element.
                const liftCues = function () {
                    const cues = track.track && track.track.cues;
                    if (!cues) return;
                    for (let c = 0; c < cues.length; c++) {
                        try {
                            cues[c].snapToLines = false;
                            cues[c].line = 72;
                            cues[c].lineAlign = 'end';
                        } catch (e) {}
                    }
                };
                track.addEventListener('load', function () {
                    liftCues();
                    // Cues may finish parsing long after the offset was set:
                    // re-apply it to every track as soon as this one loads.
                    applySubOffset();
                });
                track.addEventListener('cuechange', liftCues);
                video.appendChild(track);
                const opt = document.createElement('button');
                opt.type = 'button';
                opt.className = 'pill-option';
                opt.dataset.value = String(i);
                opt.textContent = sub.label;
                menu.appendChild(opt);
            });
            // Default on load is ALWAYS English (the server guarantees an
            // English track via "English (Auto)" when the provider has
            // none). A previous "CC off" choice never suppresses the
            // default: only the viewer's current-session click can.
            const def = preferredSubtitleIndex(subtitles);
            currentSubtitles = subtitles;
            currentSubIdx = def;
            subtitleDropdown.setActive(def);
            // Automatic selection must not overwrite the viewer's saved CC
            // preference.
            applySubtitle(def, false);
        }

        // Map through the <track> elements themselves: the order of
        // video.textTracks is NOT guaranteed to match DOM/source order
        // (Chrome re-sorts by browser language), while trackEl.track is
        // always the exact track for that element. `persist` is false for
        // automatic re-application, true for explicit viewer choices.
        function applySubtitle(idx, persist) {
            if (persist === undefined) persist = true;
            currentSubIdx = idx;
            video.querySelectorAll('track').forEach((el, i) => {
                el.track.mode = (i === idx) ? 'showing' : 'disabled';
            });
            // Browsers reset cue timings when a track is re-shown; re-apply
            // the viewer's offset so sync survives channel switching.
            applySubOffset();
            if (!persist) return;
            if (idx >= 0 && currentSubtitles[idx]) {
                localStorage.setItem('biblioanime_sub_lang', subtitleLanguage(currentSubtitles[idx], idx));
                localStorage.setItem('biblioanime_cc', 'on');
            } else if (idx === -1) {
                localStorage.setItem('biblioanime_cc', 'off');
            }
        }

        // 'C' hotkey: toggle closed captions. Turning CC back on always
        // starts from the English track when the episode has one, so the
        // hotkey never lands on an unexpected language.
        function toggleCC() {
            const tracks = video.querySelectorAll('track');
            if (!tracks.length) return;
            let showing = -1;
            tracks.forEach((el, i) => { if (el.track.mode === 'showing') showing = i; });
            if (showing >= 0) {
                subtitleDropdown.setActive(-1);
                applySubtitle(-1);
                return;
            }
            const target = preferredSubtitleIndex(currentSubtitles);
            if (target === -1) {
                // No English/Indonesian track available: stay off instead of
                // choosing an arbitrary language.
                subtitleDropdown.setActive(-1);
                applySubtitle(-1);
                return;
            }
            subtitleDropdown.setActive(target);
            applySubtitle(target);
        }

        // ===== Subtitle sync =====
        // Cue timestamps are shifted from a cached base (never from the
        // already-shifted value), so repeated taps cannot drift and the
        // result stays precise. Every track is shifted, not just the visible
        // one, and the choice is remembered per anime so the next episode of
        // the same release starts in sync too.
        function readSubOffset() {
            try {
                const raw = parseFloat(localStorage.getItem(SUB_OFFSET_PREFIX + ANIME.id) || '0');
                return isFinite(raw) ? Math.round(raw * 10) / 10 : 0;
            } catch (e) {
                return 0;
            }
        }

        function paintSubOffset() {
            const label = document.getElementById('subOffsetLabel');
            if (!label) return;
            const magnitude = Math.abs(subOffset).toFixed(1).replace(/\.0$/, '');
            label.textContent = (subOffset > 0 ? '+' : subOffset < 0 ? '\u2212' : '') + magnitude + 's';
            label.classList.toggle('text-[#f97316]', subOffset !== 0);
            label.classList.toggle('text-[#0f766e]', subOffset === 0);
            label.classList.toggle('bg-[#f97316]/10', subOffset !== 0);
            label.title = subOffset === 0
                ? 'Subtitles are on their original timing. Tap to reset.'
                : 'Offset ' + label.textContent + '. Tap to reset to 0s.';
        }

        function cueBase(cue) {
            if (cue.__bibStart === undefined) {
                cue.__bibStart = cue.startTime;
                cue.__bibEnd = cue.endTime;
            }
            return [cue.__bibStart, cue.__bibEnd];
        }

        function applySubOffset() {
            const tracks = video.textTracks;
            if (!tracks) return;
            for (let i = 0; i < tracks.length; i++) {
                const cues = tracks[i].cues;
                if (!cues) continue;
                for (let j = 0; j < cues.length; j++) {
                    const base = cueBase(cues[j]);
                    const start = Math.max(0, base[0] + subOffset);
                    const end = Math.max(start + 0.02, base[1] + subOffset);
                    try {
                        // Grow the end first when shifting later, the start
                        // first when shifting earlier: the cue never becomes
                        // invalid (end < start) mid-update.
                        if (subOffset >= 0) {
                            cues[j].endTime = end;
                            cues[j].startTime = start;
                        } else {
                            cues[j].startTime = start;
                            cues[j].endTime = end;
                        }
                    } catch (e) {}
                }
            }
        }

        function setSubOffset(value) {
            subOffset = Math.round(Math.max(-30, Math.min(30, value)) * 10) / 10;
            try {
                if (subOffset === 0) localStorage.removeItem(SUB_OFFSET_PREFIX + ANIME.id);
                else localStorage.setItem(SUB_OFFSET_PREFIX + ANIME.id, String(subOffset));
            } catch (e) {}
            paintSubOffset();
            applySubOffset();
        }

        function nudgeSubtitles(delta) {
            setSubOffset(subOffset + delta);
        }

        paintSubOffset();
        document.getElementById('subMinus').addEventListener('click', function (e) { nudgeSubtitles(e.shiftKey ? -0.1 : -0.5); });
        document.getElementById('subPlus').addEventListener('click', function (e) { nudgeSubtitles(e.shiftKey ? 0.1 : 0.5); });
        document.getElementById('subOffsetLabel').addEventListener('click', function () { setSubOffset(0); });

        // Automatic source failover: the backend walks every provider for us
        // (and the same episode in the chosen audio), so the player no longer
        // exposes a server picker. A failed manifest gets one cheap retry in
        // case a flaky provider is behind the first response.
        let fatalRecoveries = 0;

        async function loadStream() {
            hideOverlays();
            const oldFrame = document.getElementById('embedFrame');
            if (oldFrame) oldFrame.remove();
            if (video.currentTime > 5) resumeOverride = Math.floor(video.currentTime);
            resumeApplied = false;
            let data = null;
            try {
                const res = await fetch(streamUrl());
                data = await res.json();
            } catch (e) {
                showError('Could not reach the streaming service. Check your connection and try again.');
                return;
            }
            if (!data || !data.source) {
                if (data && data.unavailable) {
                    showUnavailable(data.error || 'This title has no video source on any provider.');
                } else {
                    showError((data && data.error) || 'All servers were tried automatically, but none could play this episode. Please try again later.');
                }
                return;
            }
            // Asked for a burned-in copy but the source did not have this
            // episode: the backend already fell back, so tell the viewer
            // instead of leaving a misleading "Burned-in" label.
            const versionNotice = document.getElementById('versionNotice');
            if (hardsubServer && !data.hardSub) {
                setHardsubServer('');
                if (versionNotice) versionNotice.classList.remove('hidden');
            } else if (versionNotice) {
                versionNotice.classList.add('hidden');
            }
            // A burned-in MP4 picked automatically as the last resort should
            // also show up in the picker, otherwise the label lies. Embeds are
            // excluded: pinning ?server= would force a file we may not have.
            if (data.hardSub && !hardsubServer && data.source.type === 'mp4') {
                const actual = data.provider === 'AnimeHeaven' ? 'animeheaven'
                    : (data.provider === 'Samehadaku' ? 'samehadaku' : '');
                if (actual) setHardsubServer(actual);
            }
            intro = data.intro || intro;
            outro = data.outro || outro;
            const notice = document.getElementById('audioNotice');
            if (notice) notice.classList.toggle('hidden', data.audioFallback !== 'sub');
            if (data.source.type === 'embed') {
                buildSubtitleOptions([]);
                if (data.hardSub) {
                    subtitleDropdown.btn.disabled = true;
                    subtitleDropdown.btn.title = 'Subtitles are built into this video';
                    const subLabel = document.getElementById('subtitleBtnLabel');
                    if (subLabel) subLabel.textContent = 'Burned-in';
                }
                playEmbed(data.source.url);
                loadingEl.style.display = 'none';
                return;
            }
            // Hard-subtitled sources ship a single MP4 with the text already
            // in the frames: no manifest, no tracks, no offset.
            if (data.source.type === 'mp4') {
                buildSubtitleOptions([]);
                attachFile(data.source.url);
                loadingEl.style.display = 'none';
                return;
            }
            buildSubtitleOptions(data.subtitles || []);
            attachSource(data.source.url);
            loadSkipTimes();
        }

        // Opening/ending markers come from AniSkip (via our own endpoint) and
        // are fetched separately so a slow third party never delays playback.
        async function loadSkipTimes() {
            if (skipTimesLoaded) return;
            skipTimesLoaded = true;
            try {
                const res = await fetch(SKIP_TIMES_URL + '?n=' + encodeURIComponent(EPISODE_NUMBER));
                const data = await res.json();
                intro = data.intro || intro;
                outro = data.outro || null;
            } catch (e) {}
        }

        function buildQualityOptions() {
            if (!hls || !hls.levels || !hls.levels.length) return;
            const levels = hls.levels.map((lv, idx) => ({ idx, height: lv.height || 0 })).sort((a, b) => b.height - a.height);
            const menu = qualityDropdown.menu;
            menu.innerHTML = '';
            const autoBtn = document.createElement('button');
            autoBtn.type = 'button';
            autoBtn.className = 'pill-option';
            autoBtn.dataset.value = '-1';
            autoBtn.textContent = 'Auto';
            menu.appendChild(autoBtn);
            levels.forEach(function (lv) {
                const opt = document.createElement('button');
                opt.type = 'button';
                opt.className = 'pill-option';
                opt.dataset.value = String(lv.idx);
                opt.textContent = lv.height ? lv.height + 'p' : 'Level ' + (lv.idx + 1);
                menu.appendChild(opt);
            });
            qualityDropdown.btn.disabled = false;
            if (qualityPref >= 0 && qualityPref < hls.levels.length) {
                hls.currentLevel = qualityPref;
                qualityDropdown.setActive(qualityPref);
            } else {
                hls.currentLevel = -1;
                qualityDropdown.setActive(-1);
            }
        }

        function attachSource(src) {
            if (hls) { try { hls.destroy(); } catch (e) {} hls = null; }
            playMode = 'hls';
            setEmbedWarning(false);
            stopEmbedClock();
            const oldFrame = document.getElementById('embedFrame');
            if (oldFrame) oldFrame.remove();
            // HLS segments are fetched by hls.js through XHR and our own proxy,
            // so the element keeps the anonymous CORS mode it was built with.
            video.setAttribute('crossorigin', 'anonymous');
            mp4Failed = false;
            video.style.visibility = '';
            // A previous hard-subtitled file may have disabled these.
            subtitleDropdown.btn.disabled = false;
            subtitleDropdown.btn.title = '';
            qualityDropdown.btn.disabled = false;
            qualityDropdown.btn.title = '';
            if (window.Hls && Hls.isSupported()) {
                hls = new Hls({
                    // Balanced buffer for mobile + serverless proxy: avoids flooding
                    // Vercel lambdas on startup while keeping smooth playback.
                    maxBufferLength: 30,
                    maxMaxBufferLength: 60,
                    maxBufferSize: 60 * 1024 * 1024,
                    backBufferLength: 20,
                    abrEwmaDefaultEstimate: 4000000,
                    enableWorker: true,
                    lowLatencyMode: false,
                    startLevel: -1,
                    capLevelToPlayerSize: true,
                    manifestLoadingMaxRetry: 4,
                    manifestLoadingRetryDelay: 1000,
                    levelLoadingMaxRetry: 4,
                    levelLoadingRetryDelay: 1000,
                    fragLoadingMaxRetry: 5,
                    fragLoadingRetryDelay: 1000,
                    fragLoadingTimeOut: 25000,
                });
                // Never let HLS show subtitles baked into the stream: some
                // providers embed an Arabic track and hls.js would render it
                // on top of our own English <track> selection.
                hls.subtitleDisplay = false;
                hls.subtitleTrack = -1;
                hls.loadSource(src);
                hls.attachMedia(video);
                hls.on(Hls.Events.SUBTITLE_TRACKS_UPDATED, function () {
                    hls.subtitleTrack = -1;
                });
                hls.on(Hls.Events.MANIFEST_PARSED, function () {
                    hls.subtitleTrack = -1;
                    loadingEl.style.display = 'none';
                    buildQualityOptions();
                });
                // Reset recovery counter once segments flow normally so an early
                // hiccup never prevents recovery later in a 24-minute episode.
                hls.on(Hls.Events.FRAG_LOADED, function () {
                    fatalRecoveries = 0;
                });
                hls.on(Hls.Events.ERROR, function (ev, data) {
                    if (!data || !data.fatal) return;
                    if (window.console) console.warn('[BiblioAnime HLS]', data.type, data.details, data.response && data.response.code);
                    if (data.type === Hls.ErrorTypes.NETWORK_ERROR && fatalRecoveries < 3) {
                        fatalRecoveries++;
                        setTimeout(function () {
                            try { if (hls) hls.startLoad(); } catch (e) {}
                        }, 800 * fatalRecoveries);
                        return;
                    }
                    if (data.type === Hls.ErrorTypes.MEDIA_ERROR && fatalRecoveries < 2) {
                        fatalRecoveries++;
                        try { hls.recoverMediaError(); } catch (e) {}
                        return;
                    }
                    if (fatalRecoveries < 4) {
                        fatalRecoveries = 4;
                        loadStream();
                        return;
                    }
                    showError('The stream was interrupted. Tap Try Again to reconnect.');
                });
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                qualityDropdown.btn.disabled = true;
                qualityDropdown.btn.title = 'Quality is managed automatically by the browser';
                video.src = src;
            } else {
                showError('This browser does not support HLS playback.');
                return;
            }
            video.play().catch(function () {});
        }

        // Progressive MP4 with burned-in subtitles (AnimeHeaven, Samehadaku).
        // Played by the native element: hls.js only understands playlists.
        function attachFile(src) {
            if (hls) { try { hls.destroy(); } catch (e) {} hls = null; }
            playMode = 'mp4';
            stopEmbedClock();
            const oldFrame = document.getElementById('embedFrame');
            if (oldFrame) oldFrame.remove();
            // The Samehadaku CDN sends no Access-Control-Allow-Origin (the
            // AnimeHeaven file is proxied through our own origin for the same
            // reason), so with crossorigin="anonymous" the browser refuses the
            // file and the player stays black at 0:00. The native element must
            // fetch it as an ordinary media resource.
            video.removeAttribute('crossorigin');
            mp4Failed = false;
            video.style.visibility = '';
            qualityDropdown.btn.disabled = true;
            qualityDropdown.btn.title = 'This source is a single file';
            subtitleDropdown.btn.disabled = true;
            subtitleDropdown.btn.title = 'Subtitles are built into this video';
            const subLabel = document.getElementById('subtitleBtnLabel');
            if (subLabel) subLabel.textContent = 'Burned-in';
            video.src = src;
            video.play().catch(function () {});
        }

        // A burned-in file that the browser refuses (CDN moved, hotlink
        // block, unsupported codec) must not leave a silent black frame:
        // drop the pinned version and let the standard chain take over.
        video.addEventListener('error', function () {
            if (playMode !== 'mp4' || mp4Failed) return;
            if (!video.error || video.error.code === 1) return;
            mp4Failed = true;
            setHardsubServer('');
            const notice = document.getElementById('versionNotice');
            if (notice) notice.classList.remove('hidden');
            loadStream();
        });

        video.addEventListener('loadedmetadata', function () {            // Re-assert the chosen subtitle track: browsers may reset a
            // freshly added track to hidden/disabled while its VTT loads,
            // which is why CC vanished after a refresh even though the
            // settings label still showed the right language.
            if (currentSubIdx >= 0) applySubtitle(currentSubIdx, false);
            loadingEl.style.display = 'none';
            if (!resumeApplied) {
                const target = storedResume();
                if (target > 0 && video.duration && target < video.duration - 10) {
                    video.currentTime = target;
                }
                resumeApplied = true;
            }
        });

        // Attaching a new HLS source resets text track modes in Chrome, so
        // re-assert the selection once playback actually starts as well.
        video.addEventListener('play', function () {
            if (currentSubIdx >= 0) applySubtitle(currentSubIdx, false);
        });
        video.addEventListener('canplay', function () {
            if (currentSubIdx >= 0) applySubtitle(currentSubIdx, false);
        });
        function updateSkipButtons() {
            const t = video.currentTime || 0;
            const inWindow = function (slot) { return !!slot && t >= slot.start && t < slot.end; };
            skipIntroBtn.classList.toggle('hidden', !inWindow(intro));
            skipOutroBtn.classList.toggle('hidden', !inWindow(outro));
        }

        // Auto-advance: when the final second is reached (or the episode ends),
        // go straight to the next episode when one exists.
        function maybeAutoNext(force) {
            if (autoNextDone || !nextEpisodeUrl || !video.duration || isNaN(video.duration)) return;
            if (!force && video.currentTime < video.duration - 1) return;
            autoNextDone = true;
            saveProgress(true);
            window.location.href = nextEpisodeUrl;
        }

        video.addEventListener('timeupdate', function () {
            const now = Date.now();
            if (now - lastSavedAt >= 5000) {
                lastSavedAt = now;
                saveProgress(false);
            }
            const t = video.currentTime || 0;
            updateSkipButtons();
            if (video.duration && t > video.duration - 15) {
                if (nextEpisodeUrl) {
                    nextOverlay.href = nextEpisodeUrl;
                    nextOverlay.classList.remove('hidden');
                    nextOverlay.classList.add('flex');
                }
            } else {
                nextOverlay.classList.add('hidden');
                nextOverlay.classList.remove('flex');
            }
            maybeAutoNext(false);
        });
        video.addEventListener('pause', () => saveProgress(false));
        video.addEventListener('seeked', updateSkipButtons);
        video.addEventListener('ended', function () { saveProgress(true); maybeAutoNext(true); });
        document.addEventListener('visibilitychange', function () { if (document.hidden) saveProgress(false); });
        window.addEventListener('pagehide', () => saveProgress(false));
        skipIntroBtn.addEventListener('click', function () { if (intro) video.currentTime = intro.end; });
        skipOutroBtn.addEventListener('click', function () { if (outro) video.currentTime = outro.end; });

        // Keyboard controls: F = fullscreen, Left/Right arrows = seek -/+ 5s.
        // Holding an arrow key repeats natively, so seeking is spammable.
        document.addEventListener('keydown', function (e) {
            const tag = (e.target && e.target.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
                if (!video.duration || isNaN(video.duration)) return;
                e.preventDefault();
                const delta = e.key === 'ArrowRight' ? 5 : -5;
                video.currentTime = Math.min(Math.max(0, video.currentTime + delta), Math.max(0, video.duration - 0.5));
                return;
            }
            if (e.key === 'c' || e.key === 'C') {
                e.preventDefault();
                toggleCC();
                return;
            }
            if (e.key !== 'f' && e.key !== 'F') return;
            const wrap = document.getElementById('playerWrap');
            if (!wrap) return;
            if (document.fullscreenElement) {
                document.exitFullscreen().catch(function () {});
            } else if (wrap.requestFullscreen) {
                wrap.requestFullscreen().catch(function () {});
            }
        });
        document.getElementById('retryBtn').addEventListener('click', loadStream);

        const embedWarningEl = document.getElementById('embedWarning');
        const dismissEmbedWarningBtn = document.getElementById('dismissEmbedWarning');
        if (dismissEmbedWarningBtn && embedWarningEl) {
            dismissEmbedWarningBtn.addEventListener('click', function () {
                embedWarningEl.classList.add('hidden');
                embedWarningEl.classList.remove('flex');
            });
        }

        function setEmbedWarning(show) {
            if (!embedWarningEl) return;
            embedWarningEl.classList.toggle('hidden', !show);
            embedWarningEl.classList.toggle('flex', !!show);
        }

        // Embed player: fallback for titles that only expose hosted embed pages.
        function playEmbed(src) {
            errorEl.style.display = 'none';
            loadingEl.style.display = 'none';
            const wrap = document.getElementById('playerWrap');
            if (!wrap) return;
            let frame = document.getElementById('embedFrame');
            if (!frame) {
                frame = document.createElement('iframe');
                frame.id = 'embedFrame';
                frame.className = 'absolute inset-0 z-30 h-full w-full border-0 bg-black';
                frame.setAttribute('allowfullscreen', 'true');
                frame.setAttribute('allow', 'autoplay; fullscreen; encrypted-media; picture-in-picture');
                frame.setAttribute('referrerpolicy', 'origin');
                wrap.appendChild(frame);
            }
            frame.src = src || ('https://player.vidplus.to/embed/anime/' + encodeURIComponent(ANIME.id) + '/' + EPISODE_NUMBER + '?dub=false');
            try { video.pause(); } catch (e) {}
            video.style.visibility = 'hidden';
            playMode = 'embed';
            setEmbedWarning(true);
            startEmbedClock();
            saveProgress(false);
        }
        document.getElementById('embedBtn').addEventListener('click', function () { playEmbed(); });

        // Episode list sidebar + prev/next
        (async function () {
            const listEl = document.getElementById('epList');
            try {
                const res = await fetch(EPISODES_URL + '?audio=' + encodeURIComponent(audioValue));
                const data = await res.json();
                const eps = data.episodes || [];
                paintAudioButtons();
                if (!eps.length) {
                    listEl.innerHTML = '<p class="py-6 text-center text-xs font-medium text-[#84948a]">No episodes available yet.</p>';
                    return;
                }
                listEl.innerHTML = '';
                eps.forEach(function (ep) {
                    const a = document.createElement('a');
                    a.dataset.epId = ep.id;
                    a.dataset.epN = ep.number;
                    a.dataset.epAudio = audioValue;
                    a.href = watchHref(ep.id, ep.number, audioValue);
                    a.className = 'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm transition ' + (ep.number === EPISODE_NUMBER ? 'bg-[#0f766e] font-bold text-white' : 'font-semibold text-[#4c5b54] hover:bg-[#f6f1e8]');
                    a.innerHTML = '<span class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full ' + (ep.number === EPISODE_NUMBER ? 'bg-white/20' : 'bg-[#172a37] text-white') + '"><span class="material-symbols-rounded text-sm">play_arrow</span></span><span class="min-w-0"><span class="block">Episode ' + ep.number + '</span>' + (ep.title ? '<span class="block truncate text-[11px] font-medium opacity-70">' + ep.title.replace(/</g, '&lt;') + '</span>' : '') + '</span>';
                    listEl.appendChild(a);
                });
                const idx = eps.findIndex(e => e.number === EPISODE_NUMBER);
                const prev = idx > 0 ? eps[idx - 1] : null;
                const next = idx >= 0 && idx < eps.length - 1 ? eps[idx + 1] : null;
                const prevBtn = document.getElementById('prevEpBtn');
                const nextBtn = document.getElementById('nextEpBtn');
                if (prev) {
                    prevBtn.dataset.epId = prev.id;
                    prevBtn.dataset.epN = prev.number;
                    prevBtn.dataset.epAudio = audioValue;
                    prevBtn.href = watchHref(prev.id, prev.number, audioValue);
                    prevBtn.classList.remove('hidden');
                    prevBtn.classList.add('inline-flex');
                }
                if (next) {
                    nextBtn.dataset.epId = next.id;
                    nextBtn.dataset.epN = next.number;
                    nextBtn.dataset.epAudio = audioValue;
                    nextBtn.href = watchHref(next.id, next.number, audioValue);
                    nextBtn.classList.remove('hidden');
                    nextBtn.classList.add('inline-flex');
                    nextEpisodeUrl = nextBtn.href;
                }
            } catch (e) {
                listEl.innerHTML = '<p class="py-6 text-center text-xs font-medium text-[#84948a]">Failed to load the episode list.</p>';
            }
        })();

        // Fullscreen idle-hide: when the mouse stays still in fullscreen,
        // hide the cursor and the native video controls so nothing covers
        // the picture; any mouse movement brings them back.
        (function () {
            const wrap = document.getElementById('playerWrap');
            if (!wrap) return;
            let idleTimer = null;
            function inFullscreen() {
                const fs = document.fullscreenElement || document.webkitFullscreenElement;
                return !!fs && (fs === wrap || fs === video || wrap.contains(fs));
            }
            function clearHide() {
                wrap.classList.remove('hide-ui');
                if (idleTimer) clearTimeout(idleTimer);
                idleTimer = null;
            }
            function armIdleHide() {
                clearHide();
                // Paused playback keeps the native bar up on purpose: that is
                // when the user is actually reaching for it.
                if (inFullscreen() && !video.paused) {
                    idleTimer = setTimeout(function () { wrap.classList.add('hide-ui'); }, 2500);
                }
            }
            wrap.addEventListener('mousemove', armIdleHide);
            wrap.addEventListener('touchstart', armIdleHide, { passive: true });
            video.addEventListener('play', armIdleHide);
            video.addEventListener('pause', clearHide);
            document.addEventListener('fullscreenchange', function () {
                if (inFullscreen()) {
                    armIdleHide();
                } else {
                    clearHide();
                }
            });
            // Native controls fullscreen the <video>, which does not always
            // fire a bubbling event; listen on the element too.
            video.addEventListener('webkitbeginfullscreen', armIdleHide);
            video.addEventListener('webkitendfullscreen', clearHide);
        })();

        // Recommendations: fetched lazily AFTER the stream starts loading so
        // it never competes with the video for bandwidth or server time.
        setTimeout(async function () {
            const recoEl = document.getElementById('recoList');
            if (!recoEl) return;
            try {
                const res = await fetch('/anime/' + ANIME.id + '/recommendations.json');
                const data = await res.json();
                const items = data.recommendations || [];
                if (!items.length) {
                    recoEl.innerHTML = '<p class="py-4 text-center text-xs font-medium text-[#84948a]">No recommendations yet.</p>';
                    return;
                }
                recoEl.innerHTML = '';
                items.forEach(function (item) {
                    const a = document.createElement('a');
                    a.href = '/anime/' + item.id;
                    a.className = 'group flex gap-3 rounded-2xl p-1.5 transition hover:bg-[#f6f1e8]';
                    const img = document.createElement('img');
                    img.src = item.bannerUrl || item.coverUrl || '';
                    img.alt = item.title;
                    img.loading = 'lazy';
                    img.decoding = 'async';
                    img.className = 'aspect-video w-28 flex-shrink-0 rounded-xl object-cover bg-[#172a37]';
                    const meta = [];
                    if (item.score) meta.push('★ ' + item.score);
                    if (item.year) meta.push(item.year);
                    if (item.episodes) meta.push(item.episodes + ' Episode');
                    const body = document.createElement('div');
                    body.className = 'min-w-0 flex-1 py-0.5';
                    const title = document.createElement('p');
                    title.className = 'truncate text-[13px] font-bold text-[#172a37] transition-colors group-hover:text-[#0f766e]';
                    title.textContent = item.title;
                    const sub = document.createElement('p');
                    sub.className = 'mt-0.5 text-[11px] font-semibold text-[#84948a]';
                    sub.textContent = meta.join(' · ');
                    body.appendChild(title);
                    body.appendChild(sub);
                    if (item.genres && item.genres.length) {
                        const g = document.createElement('p');
                        g.className = 'mt-0.5 truncate text-[11px] font-medium text-[#0f766e]';
                        g.textContent = item.genres.join(' · ');
                        body.appendChild(g);
                    }
                    a.appendChild(img);
                    a.appendChild(body);
                    recoEl.appendChild(a);
                });
            } catch (e) {
                recoEl.innerHTML = '<p class="py-4 text-center text-xs font-medium text-[#84948a]">Recommendations are unavailable right now.</p>';
            }
        }, 1200);

        // Basic native player controls: the browser renders its own control bar,
        // so only the helpers still used elsewhere on the page are kept here.
        const playerWrap = document.getElementById('playerWrap');

        function togglePlay() {
            if (video.paused || video.ended) video.play().catch(function () {});
            else video.pause();
        }


        // Keyboard shortcuts: C = subtitles, arrows = seek, F = fullscreen (existing listener above).
        // Space = play/pause, but only when the native player is NOT focused:
        // a focused <video controls> already handles Space itself, and doing
        // both toggled twice (pause then instant play).
        document.addEventListener('keydown', function (e) {
            if (e.code !== 'Space' || e.repeat) return;
            const target = e.target;
            const tag = (target && target.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            if (target && target.closest && target.closest('button, a, [contenteditable="true"]')) return;
            if (document.activeElement === video) return;
            e.preventDefault();
            togglePlay();
        });

        // Record the episode immediately so "Continue Watching" is populated
        // even if the viewer leaves before the first timeupdate, then keep
        // refreshing it every few seconds only while actively watching.
        saveProgress(false);
        setInterval(() => {
            if (document.hidden) return;
            if (playMode !== 'embed' && video.paused) return;
            saveProgress(false);
        }, 5000);

        loadStream();
    })();
    </script>
</x-manga-layout>
