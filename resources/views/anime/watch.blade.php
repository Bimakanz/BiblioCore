<x-manga-layout title="{{ $anime['title'] }} Episode {{ $episodeNumber }} — BiblioAnime">
    <div class="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 space-y-5">

        {{-- Breadcrumb --}}
        <div class="flex flex-wrap items-center gap-2 text-xs font-semibold text-[#84948a]">
            <a href="{{ route('anime.index') }}" class="hover:text-[#0f766e]">Anime</a>
            <span class="material-symbols-rounded text-sm">chevron_right</span>
            <a href="{{ route('anime.show', ['id' => $anime['id']]) }}" class="hover:text-[#0f766e]">{{ $anime['title'] }}</a>
            <span class="material-symbols-rounded text-sm">chevron_right</span>
            <span class="text-[#172a37]">Episode {{ $episodeNumber }}</span>
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            {{-- ===== PLAYER COLUMN ===== --}}
            <div class="space-y-4 lg:col-span-2">
                <div id="playerWrap" class="relative overflow-hidden rounded-[1.75rem] bg-black shadow-[0_25px_60px_-25px_rgba(0,0,0,0.7)] group">
                    <video id="player" class="aspect-video w-full bg-black" crossorigin="anonymous" playsinline
                           poster="{{ $anime['bannerUrl'] ?: $anime['coverUrl'] }}"></video>

                    <div id="playerLoading" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/60 text-white backdrop-blur-[2px] z-40">
                        <div class="relative h-14 w-14 overflow-hidden rounded-2xl">
                            <img src="{{ asset('images/loading/loading-1.webp') }}" alt="" fetchpriority="high" class="loader-img loader-img-1">
                            <img src="{{ asset('images/loading/loading-2.webp') }}" alt="" fetchpriority="high" class="loader-img loader-img-2">
                        </div>
                        <p class="text-sm font-semibold">Loading wait a damn minute wontcha?</p>
                        <p class="text-xs text-white/60">Sumber langsung tanpa embed &amp; tanpa iklan</p>
                    </div>

                    <div id="playerError" class="absolute inset-0 hidden flex-col items-center justify-center gap-3 bg-black/85 p-6 text-center text-white z-40">
                        <span class="material-symbols-rounded text-4xl text-[#f97316]">error</span>
                        <p id="playerErrorText" class="max-w-md text-sm font-medium">Stream is not available for this episode.</p>
                        <button type="button" id="retryBtn" class="rounded-full bg-[#f97316] px-5 py-2 text-xs font-bold text-white transition hover:bg-[#ea580c]">Try Again</button>
                    </div>

                    <!-- Custom dark green player controls -->
                    <div id="playerControls" class="absolute inset-0 flex flex-col z-20 opacity-0 transition-opacity duration-300 pointer-events-none">
                        <!-- Center play/pause overlay -->
                        <div id="playerCenterPlay" class="flex items-center justify-center z-30">
                            <button id="centerPlayBtn" class="w-16 h-16 rounded-full bg-black/55 hover:bg-black/75 text-white flex items-center justify-center transition-all backdrop-blur-sm shadow-xl">
                                <span class="material-symbols-rounded text-3xl">play_arrow</span>
                            </button>
                        </div>

                        <!-- Bottom control bar (floating rounded panel) -->
                        <div class="absolute bottom-0 left-0 right-0 z-30 px-2.5 pb-2.5 sm:px-4 sm:pb-4">
                            <div class="control-bar rounded-2xl bg-black/75 backdrop-blur-md border border-white/10 px-3 py-2.5 shadow-[0_12px_32px_-12px_rgba(0,0,0,0.85)]">
                                <div class="flex items-center gap-2.5 sm:gap-3">
                                    <!-- Play/pause -->
                                    <button id="barPlayBtn" class="h-9 w-9 flex-shrink-0 rounded-full bg-[#0f766e] hover:bg-[#115e59] text-white flex items-center justify-center transition-all shadow-lg transform active:scale-95">
                                        <span class="material-symbols-rounded text-lg">play_arrow</span>
                                    </button>

                                    <!-- Time + progress -->
                                    <span id="barCurrentTime" class="flex-shrink-0 text-[11px] font-mono font-semibold text-white/75 tabular-nums">0:00</span>
                                    <div id="progressTrack" class="flex-1 min-w-0 h-1.5 rounded-full bg-white/25 cursor-pointer relative group/progress">
                                        <div id="progressFill" class="h-1.5 rounded-full bg-[#0f766e] w-0 transition-all duration-100"></div>
                                        <div id="progressHandle" class="absolute left-0 top-1/2 -translate-y-1/2 w-3 h-3 bg-[#0f766e] rounded-full shadow-md opacity-0 transition-opacity duration-200 group-hover/progress:opacity-100"></div>
                                    </div>
                                    <span id="barDuration" class="flex-shrink-0 text-[11px] font-mono font-semibold text-white/75 tabular-nums">0:00</span>

                                    <!-- Volume + Fullscreen -->
                                    <div class="flex flex-shrink-0 items-center gap-1.5 sm:gap-2">
                                        <button id="barVolumeBtn" class="h-9 w-9 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-all active:scale-95">
                                            <span class="material-symbols-rounded text-lg">volume_up</span>
                                        </button>
                                        <button id="barFullscreenBtn" class="h-9 w-9 rounded-full bg-white/10 hover:bg-white/20 text-white flex items-center justify-center transition-all active:scale-95">
                                            <span class="material-symbols-rounded text-lg">fullscreen</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
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

                {{-- Control bar --}}
                <div class="flex flex-wrap items-center gap-3 rounded-3xl border border-[#e0d9c8] bg-white p-4 shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] font-bold uppercase tracking-wide text-[#84948a]">Server</span>
                        <div class="relative">
                            <button type="button" id="serverBtn" class="pill-btn"><span id="serverBtnLabel">Auto</span></button>
                            <div id="serverMenu" class="pill-menu hidden">
                                <button type="button" class="pill-option is-active" data-value="">Auto</button>
                                @foreach($servers as $srv)
                                    <button type="button" class="pill-option" data-value="{{ $srv }}">{{ ucfirst($srv) }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] font-bold uppercase tracking-wide text-[#84948a]">Quality</span>
                        <div class="relative">
                            <button type="button" id="qualityBtn" class="pill-btn"><span id="qualityBtnLabel">Auto</span></button>
                            <div id="qualityMenu" class="pill-menu hidden">
                                <button type="button" class="pill-option is-active" data-value="-1">Auto</button>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] font-bold uppercase tracking-wide text-[#84948a]">Subtitle</span>
                        <div class="relative">
                            <button type="button" id="subtitleBtn" class="pill-btn"><span id="subtitleBtnLabel">Off</span></button>
                            <div id="subtitleMenu" class="pill-menu pill-menu-right hidden">
                                <button type="button" class="pill-option is-active" data-value="-1">Off</button>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="text-[11px] font-bold uppercase tracking-wide text-[#84948a]">Subtitle Offset</span>
                        <button type="button" id="subMinus" class="rounded-full border border-[#dcd2bd] bg-white px-2.5 py-1 text-xs font-bold text-[#172a37] transition hover:border-[#0f766e]/50">-0,5s</button>
                        <span id="subOffsetLabel" class="min-w-[38px] text-center text-xs font-bold text-[#0f766e]">0s</span>
                        <button type="button" id="subPlus" class="rounded-full border border-[#dcd2bd] bg-white px-2.5 py-1 text-xs font-bold text-[#172a37] transition hover:border-[#0f766e]/50">+0,5s</button>
                    </div>
                </div>

                <div class="rounded-3xl border border-[#e0d9c8] bg-white p-5 shadow-sm">
                    <h1 class="text-lg sm:text-xl font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">{{ $anime['title'] }} — Episode {{ $episodeNumber }}</h1>
                    <p class="mt-1 text-xs font-semibold text-[#84948a]">{{ $anime['format'] }} &middot; {{ $anime['status'] }} @if($anime['year']) &middot; {{ $anime['year'] }} @endif @if($anime['score']) &middot; Score {{ $anime['score'] }}/10 @endif</p>
                    <p class="mt-3 text-sm leading-relaxed text-[#4c5b54] line-clamp-3">{{ $anime['description'] }}</p>
                </div>
            </div>

            {{-- ===== RIGHT COLUMN: episodes + recommendations ===== --}}
            <div class="space-y-5">
            <aside class="rounded-3xl border border-[#e0d9c8] bg-white p-5 shadow-sm h-fit">
                <h2 class="mb-3 text-sm font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">Episode List</h2>
                <div id="epList" class="custom-scroll max-h-[560px] space-y-1.5 overflow-y-auto pr-1">
                    <div class="space-y-1.5">
                        @for($i = 0; $i < 8; $i++)
                            <div class="h-10 animate-pulse rounded-xl bg-[#f1ebd8]"></div>
                        @endfor
                    </div>
                </div>
            </aside>

            <aside class="rounded-3xl border border-[#e0d9c8] bg-white p-5 shadow-sm h-fit">
                <h2 class="mb-3 text-sm font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">You Might Like</h2>
                <p class="mb-3 text-[11px] font-medium text-[#84948a]">Based on genres similar to what you watch</p>
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
        .pill-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 92px; border-radius: 9999px; border: 1px solid #dcd2bd; background-color: #fbf9f4; padding: 6px 16px; font-size: 12px; font-weight: 600; color: #172a37; cursor: pointer; transition: border-color 0.2s, box-shadow 0.2s, background-color 0.2s; }
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
        #playerWrap.hide-ui video::-webkit-media-controls-panel { opacity: 0; transition: opacity 0.3s; }
        #playerWrap video::-webkit-media-controls-panel { transition: opacity 0.3s; }

        /* ===== Custom dark green video player controls ===== */
        video { background: #000; }

        /* Hide native controls entirely */
        video::-webkit-media-controls { display: none !important; }
        video::-webkit-media-controls-panel { display: none !important; }
        video::-webkit-media-controls-enclosure { display: none !important; }
        video::-webkit-media-controls-start-playback-button { display: none !important; }

        /* Controls visibility */
        #playerControls { opacity: 0; pointer-events: none; }
        #playerControls.controls-visible { opacity: 1; pointer-events: auto; }

        /* Hide center play button when video is playing */
        #playerWrap.playing #playerCenterPlay { opacity: 0; pointer-events: none; }
        #playerWrap:not(.playing) #playerCenterPlay { opacity: 1; pointer-events: auto; }

        /* Progress bar hover effects */
        #progressTrack:hover #progressFill { opacity: 0.9; }
        #progressTrack:hover #progressHandle { opacity: 1; transform: translate(-50%, -50%) scale(1.2); }

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
        };
        const EPISODE_ID = @json($episodeId);
        const EPISODE_NUMBER = {{ (int) $episodeNumber }};
        const STREAM_URL = @json($streamUrl);
        const EPISODES_URL = @json($episodesUrl);
        const SKIP_TIMES_URL = @json(route('anime.skip-times.json', ['id' => $anime['id']]));
        const URL_T = {{ (int) request()->query('t', 0) }};
        const HISTORY_KEY = 'biblioanime_history';

        const video = document.getElementById('player');
        const loadingEl = document.getElementById('playerLoading');
        const errorEl = document.getElementById('playerError');
        const errorText = document.getElementById('playerErrorText');
        const skipIntroBtn = document.getElementById('skipIntroBtn');
        const skipOutroBtn = document.getElementById('skipOutroBtn');
        const nextOverlay = document.getElementById('nextEpOverlay');
        let qualityPref = -1;
        try { qualityPref = parseInt(localStorage.getItem('biblioanime_quality') || '-1', 10); if (isNaN(qualityPref)) qualityPref = -1; } catch (e) {}
        let serverValue = '';

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
                menu.classList.toggle('hidden');
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

        const serverDropdown = setupDropdown('serverBtn', 'serverMenu', 'serverBtnLabel', function (v) {
            serverValue = v;
            loadStream();
        });
        const qualityDropdown = setupDropdown('qualityBtn', 'qualityMenu', 'qualityBtnLabel', function (v) {
            qualityPref = parseInt(v, 10);
            if (isNaN(qualityPref)) qualityPref = -1;
            try { localStorage.setItem('biblioanime_quality', String(qualityPref)); } catch (e) {}
            if (hls) hls.currentLevel = qualityPref;
        });
        const subtitleDropdown = setupDropdown('subtitleBtn', 'subtitleMenu', 'subtitleBtnLabel', function (v) {
            applySubtitle(parseInt(v, 10));
        });

        let hls = null;
        let intro = null;
        let outro = null;
        let skipTimesLoaded = false;
        let nextEpisodeUrl = null;
        let autoNextDone = false;
        let subOffset = 0;
        let lastSavedAt = 0;
        let resumeApplied = false;
        let resumeOverride = 0;

        function readHistory() {
            try { return JSON.parse(localStorage.getItem(HISTORY_KEY) || '[]'); } catch (e) { return []; }
        }

        function saveProgress(finished) {
            if (!video.duration || isNaN(video.duration)) return;
            const entry = {
                id: ANIME.id,
                title: ANIME.title,
                coverUrl: ANIME.coverUrl,
                bannerUrl: ANIME.bannerUrl,
                episodeId: EPISODE_ID,
                episodeNumber: EPISODE_NUMBER,
                positionSec: finished ? 0 : Math.floor(video.currentTime || 0),
                durationSec: Math.floor(video.duration || 0),
                watchedAt: new Date().toLocaleDateString('id-ID'),
                updatedAt: Date.now()
            };
            let list = readHistory().filter(h => h.id !== ANIME.id);
            list.unshift(entry);
            list = list.slice(0, 30);
            try { localStorage.setItem(HISTORY_KEY, JSON.stringify(list)); } catch (e) {}
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
                track.addEventListener('load', liftCues);
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

        function shiftSubtitles(delta) {
            subOffset = Math.round((subOffset + delta) * 10) / 10;
            document.getElementById('subOffsetLabel').textContent = (subOffset > 0 ? '+' : '') + String(subOffset).replace('.', ',') + 's';
            const tracks = video.textTracks;
            for (let i = 0; i < tracks.length; i++) {
                if (tracks[i].mode === 'showing' && tracks[i].cues) {
                    for (let j = 0; j < tracks[i].cues.length; j++) {
                        try {
                            tracks[i].cues[j].startTime = Math.max(0, tracks[i].cues[j].startTime + delta);
                            tracks[i].cues[j].endTime = Math.max(0, tracks[i].cues[j].endTime + delta);
                        } catch (e) {}
                    }
                }
            }
        }
        document.getElementById('subMinus').addEventListener('click', () => shiftSubtitles(-0.5));
        document.getElementById('subPlus').addEventListener('click', () => shiftSubtitles(0.5));

        // Automatic server failover: when a source cannot be loaded or dies
        // mid-playback, the player walks the server list by itself instead
        // of leaving the user on an error screen.
        let triedServers = new Set();
        let fatalRecoveries = 0;

        function tryAutoFailover() {
            const candidates = Array.from(serverDropdown.menu.querySelectorAll('.pill-option')).map(o => o.dataset.value);
            const next = candidates.find(v => !triedServers.has(v));
            if (next === undefined) {
                return false;
            }
            triedServers.add(next);
            serverValue = next;
            serverDropdown.setActive(next);
            loadStream(true);
            return true;
        }

        async function loadStream(auto = false) {
            hideOverlays();
            if (video.currentTime > 5) resumeOverride = Math.floor(video.currentTime);
            resumeApplied = false;
            fatalRecoveries = 0;
            const server = serverValue;
            if (!auto) triedServers = new Set([server]);
            const url = STREAM_URL + (server ? '&server=' + encodeURIComponent(server) : '');
            let data = null;
            try {
                const res = await fetch(url);
                data = await res.json();
                if (!res.ok || !data.source) throw new Error(data.error || 'Stream unavailable');
            } catch (e) {
                if (!tryAutoFailover()) {
                    showError('All servers were tried automatically, but none could play this episode. Please try again later.');
                }
                return;
            }
            intro = data.intro || intro;
            outro = data.outro || outro;
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
            if (window.Hls && Hls.isSupported()) {
                hls = new Hls({
                    // Long episodes (40-60 min) need a deep forward buffer so
                    // a short CDN hiccup never reaches the decoder.
                    maxBufferLength: 90,
                    maxMaxBufferLength: 180,
                    maxBufferSize: 120 * 1024 * 1024,
                    backBufferLength: 30,
                    abrEwmaDefaultEstimate: 8000000,
                    enableWorker: true,
                    lowLatencyMode: false,
                    startLevel: -1,
                    capLevelToPlayerSize: true,
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
                hls.on(Hls.Events.ERROR, function (ev, data) {
                    if (!data || !data.fatal) return;
                    if (data.type === Hls.ErrorTypes.MEDIA_ERROR && fatalRecoveries < 1) {
                        fatalRecoveries++;
                        try { hls.recoverMediaError(); } catch (e) {}
                        return;
                    }
                    if (!tryAutoFailover()) {
                        showError('The stream was interrupted and all backup servers were tried automatically.');
                    }
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

        video.addEventListener('loadedmetadata', function () {
            // Re-assert the chosen subtitle track: browsers may reset a
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

        // Episode list sidebar + prev/next
        (async function () {
            const listEl = document.getElementById('epList');
            try {
                const res = await fetch(EPISODES_URL);
                const data = await res.json();
                const eps = data.episodes || [];
                if (!eps.length) {
                    listEl.innerHTML = '<p class="py-6 text-center text-xs font-medium text-[#84948a]">No episodes available yet.</p>';
                    return;
                }
                listEl.innerHTML = '';
                eps.forEach(function (ep) {
                    const a = document.createElement('a');
                    a.href = '/anime/' + ANIME.id + '/watch?ep=' + encodeURIComponent(ep.id) + '&n=' + ep.number;
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
                    prevBtn.href = '/anime/' + ANIME.id + '/watch?ep=' + encodeURIComponent(prev.id) + '&n=' + prev.number;
                    prevBtn.classList.remove('hidden');
                    prevBtn.classList.add('inline-flex');
                }
                if (next) {
                    nextBtn.href = '/anime/' + ANIME.id + '/watch?ep=' + encodeURIComponent(next.id) + '&n=' + next.number;
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
            function armIdleHide() {
                wrap.classList.remove('hide-ui');
                if (idleTimer) clearTimeout(idleTimer);
                if (document.fullscreenElement === wrap) {
                    idleTimer = setTimeout(function () { wrap.classList.add('hide-ui'); }, 2500);
                }
            }
            wrap.addEventListener('mousemove', armIdleHide);
            document.addEventListener('fullscreenchange', function () {
                if (document.fullscreenElement === wrap) {
                    armIdleHide();
                } else {
                    wrap.classList.remove('hide-ui');
                    if (idleTimer) clearTimeout(idleTimer);
                }
            });
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

        // ===== Custom dark green player controls =====
        const playerWrap = document.getElementById('playerWrap');
        const playerControls = document.getElementById('playerControls');
        const centerPlayBtn = document.getElementById('centerPlayBtn');
        const barPlayBtn = document.getElementById('barPlayBtn');
        const progressTrack = document.getElementById('progressTrack');
        const progressFill = document.getElementById('progressFill');
        const progressHandle = document.getElementById('progressHandle');
        const barCurrentTime = document.getElementById('barCurrentTime');
        const barDuration = document.getElementById('barDuration');
        const barVolumeBtn = document.getElementById('barVolumeBtn');
        const barFullscreenBtn = document.getElementById('barFullscreenBtn');

        let controlsTimer = null;
        let isDragging = false;

        function formatTime(sec) {
            if (!sec || isNaN(sec)) return '0:00';
            const m = Math.floor(sec / 60);
            const s = Math.floor(sec % 60).toString().padStart(2, '0');
            return m + ':' + s;
        }

        function togglePlay() {
            if (video.paused || video.ended) video.play().catch(function () {});
            else video.pause();
        }

        function updatePlayBtn() {
            const icon = video.paused ? 'play_arrow' : 'pause';
            barPlayBtn.innerHTML = '<span class="material-symbols-rounded text-lg">' + icon + '</span>';
            centerPlayBtn.innerHTML = '<span class="material-symbols-rounded text-3xl">' + icon + '</span>';
            if (video.paused || video.ended) {
                playerWrap.classList.remove('playing');
            } else {
                playerWrap.classList.add('playing');
            }
        }

        function showControls() {
            playerControls.classList.add('controls-visible');
            if (controlsTimer) clearTimeout(controlsTimer);
            if (!document.fullscreenElement) {
                controlsTimer = setTimeout(function () {
                    if (!video.paused && !isDragging) playerControls.classList.remove('controls-visible');
                }, 3000);
            }
        }

        function hideControls() {
            if (controlsTimer) clearTimeout(controlsTimer);
            if (!video.paused && !isDragging) playerControls.classList.remove('controls-visible');
        }

        function updateProgress() {
            if (isDragging) return;
            const duration = video.duration || 0;
            const current = video.currentTime || 0;
            const pct = duration > 0 ? Math.min(100, (current / duration) * 100) : 0;
            progressFill.style.width = pct + '%';
            progressHandle.style.right = 'auto';
            progressHandle.style.left = pct + '%';
            barCurrentTime.textContent = formatTime(current);
            barDuration.textContent = formatTime(duration);
        }

        function seekFromEvent(e) {
            if (!video.duration || isNaN(video.duration)) return;
            const rect = progressTrack.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const pct = Math.min(100, Math.max(0, (x / rect.width) * 100));
            progressFill.style.width = pct + '%';
            video.currentTime = (pct / 100) * video.duration;
        }

        // Play/pause
        barPlayBtn.addEventListener('click', function (e) { e.stopPropagation(); togglePlay(); });
        centerPlayBtn.addEventListener('click', function (e) { e.stopPropagation(); togglePlay(); });
        video.addEventListener('click', function () { togglePlay(); });

        // Progress: click to seek
        progressTrack.addEventListener('click', seekFromEvent);
        progressTrack.addEventListener('mousedown', function (e) {
            isDragging = true;
            seekFromEvent(e);
            document.addEventListener('mousemove', seekFromEvent);
            document.addEventListener('mouseup', function () {
                isDragging = false;
                document.removeEventListener('mousemove', seekFromEvent);
                showControls();
            });
        });

        // Time update
        video.addEventListener('timeupdate', updateProgress);
        video.addEventListener('play', function () { updatePlayBtn(); showControls(); });
        video.addEventListener('pause', function () { updatePlayBtn(); showControls(); });
        video.addEventListener('ended', function () { updatePlayBtn(); showControls(); });

        // Show/hide controls on interaction
        playerWrap.addEventListener('mousemove', showControls);
        playerWrap.addEventListener('click', showControls);
        playerWrap.addEventListener('mouseleave', hideControls);

        // Volume toggle
        barVolumeBtn.addEventListener('click', function () {
            video.muted = !video.muted;
            barVolumeBtn.innerHTML = '<span class="material-symbols-rounded text-lg">' + (video.muted ? 'volume_off' : 'volume_up') + '</span>';
            showControls();
        });

        // Fullscreen
        barFullscreenBtn.addEventListener('click', function () {
            if (document.fullscreenElement) {
                document.exitFullscreen().catch(function () {});
            } else if (playerWrap.requestFullscreen) {
                playerWrap.requestFullscreen().catch(function () {});
            }
        });

        // Fullscreen native event: keep custom controls visible (our CSS handles fullscreen)
        document.addEventListener('fullscreenchange', function () {
            if (document.fullscreenElement === playerWrap) {
                playerWrap.classList.add('fs');
            } else {
                playerWrap.classList.remove('fs');
            }
            showControls();
        });

        // Keyboard shortcuts: C = subtitles, arrows = seek, F = fullscreen (existing listener above).
        // Space added here for play/pause.
        document.addEventListener('keydown', function (e) {
            const tag = (e.target && e.target.tagName) || '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
            if (e.code === 'Space') {
                e.preventDefault();
                togglePlay();
            }
        });

        loadStream();
    })();
    </script>
</x-manga-layout>
