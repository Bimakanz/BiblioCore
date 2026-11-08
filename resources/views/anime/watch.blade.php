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
                <div id="playerWrap" class="relative overflow-hidden rounded-[1.75rem] bg-black shadow-[0_25px_60px_-25px_rgba(0,0,0,0.7)]">
                    <video id="player" class="aspect-video w-full bg-black" controls crossorigin="anonymous" playsinline
                           poster="{{ $anime['bannerUrl'] ?: $anime['coverUrl'] }}"></video>

                    <div id="playerLoading" class="absolute inset-0 flex flex-col items-center justify-center gap-3 bg-black/70 text-white">
                        <div class="h-10 w-10 animate-spin rounded-full border-[3px] border-white/30 border-t-white"></div>
                        <p class="text-sm font-semibold">Menyiapkan stream...</p>
                        <p class="text-xs text-white/60">Sumber langsung tanpa embed &amp; tanpa iklan</p>
                    </div>

                    <div id="playerError" class="absolute inset-0 hidden flex-col items-center justify-center gap-3 bg-black/85 p-6 text-center text-white">
                        <span class="material-symbols-rounded text-4xl text-[#f97316]">error</span>
                        <p id="playerErrorText" class="max-w-md text-sm font-medium">Stream belum tersedia untuk episode ini.</p>
                        <button type="button" id="retryBtn" class="rounded-full bg-[#f97316] px-5 py-2 text-xs font-bold text-white transition hover:bg-[#ea580c]">Coba Lagi</button>
                    </div>

                    <button type="button" id="skipIntroBtn" class="absolute bottom-20 right-4 hidden rounded-full bg-white/95 px-4 py-2 text-xs font-bold text-[#172a37] shadow-lg transition hover:bg-white">
                        Lewati Intro &raquo;
                    </button>

                    <a id="nextEpOverlay" href="#" class="absolute bottom-20 right-4 hidden items-center gap-2 rounded-full bg-[#f97316] px-4 py-2 text-xs font-bold text-white shadow-lg transition hover:bg-[#ea580c]">
                        Episode Berikutnya <span class="material-symbols-rounded text-base">skip_next</span>
                    </a>
                </div>

                {{-- Episode navigation: dedicated row under the player --}}
                <div class="flex items-center justify-between gap-3">
                    <a id="prevEpBtn" href="#" class="hidden items-center gap-1.5 rounded-full border border-[#dcd2bd] bg-white px-4 py-2 text-xs font-bold text-[#172a37] shadow-sm transition hover:border-[#0f766e]/60 hover:text-[#0f766e]">
                        <span class="material-symbols-rounded text-base">skip_previous</span> Episode Sebelumnya
                    </a>
                    <a id="nextEpBtn" href="#" class="hidden items-center gap-1.5 rounded-full bg-[#0f766e] px-4 py-2 text-xs font-bold text-white shadow-sm transition hover:bg-[#115e59]">
                        Episode Berikutnya <span class="material-symbols-rounded text-base">skip_next</span>
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
                        <span class="text-[11px] font-bold uppercase tracking-wide text-[#84948a]">Kualitas</span>
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
                            <button type="button" id="subtitleBtn" class="pill-btn"><span id="subtitleBtnLabel">Nonaktif</span></button>
                            <div id="subtitleMenu" class="pill-menu pill-menu-right hidden">
                                <button type="button" class="pill-option is-active" data-value="-1">Nonaktif</button>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="text-[11px] font-bold uppercase tracking-wide text-[#84948a]">Geser Sub</span>
                        <button type="button" id="subMinus" class="rounded-full border border-[#dcd2bd] bg-white px-2.5 py-1 text-xs font-bold text-[#172a37] transition hover:border-[#0f766e]/50">-0,5s</button>
                        <span id="subOffsetLabel" class="min-w-[38px] text-center text-xs font-bold text-[#0f766e]">0s</span>
                        <button type="button" id="subPlus" class="rounded-full border border-[#dcd2bd] bg-white px-2.5 py-1 text-xs font-bold text-[#172a37] transition hover:border-[#0f766e]/50">+0,5s</button>
                    </div>
                </div>

                <div class="rounded-3xl border border-[#e0d9c8] bg-white p-5 shadow-sm">
                    <h1 class="text-lg sm:text-xl font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">{{ $anime['title'] }} — Episode {{ $episodeNumber }}</h1>
                    <p class="mt-1 text-xs font-semibold text-[#84948a]">{{ $anime['format'] }} &middot; {{ $anime['status'] }} @if($anime['year']) &middot; {{ $anime['year'] }} @endif @if($anime['score']) &middot; Skor {{ $anime['score'] }}/10 @endif</p>
                    <p class="mt-3 text-sm leading-relaxed text-[#4c5b54] line-clamp-3">{{ $anime['description'] }}</p>
                </div>
            </div>

            {{-- ===== RIGHT COLUMN: episodes + recommendations ===== --}}
            <div class="space-y-5">
            <aside class="rounded-3xl border border-[#e0d9c8] bg-white p-5 shadow-sm h-fit">
                <h2 class="mb-3 text-sm font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">Daftar Episode</h2>
                <div id="epList" class="custom-scroll max-h-[560px] space-y-1.5 overflow-y-auto pr-1">
                    <div class="space-y-1.5">
                        @for($i = 0; $i < 8; $i++)
                            <div class="h-10 animate-pulse rounded-xl bg-[#f1ebd8]"></div>
                        @endfor
                    </div>
                </div>
            </aside>

            <aside class="rounded-3xl border border-[#e0d9c8] bg-white p-5 shadow-sm h-fit">
                <h2 class="mb-3 text-sm font-bold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">Mungkin Kamu Suka</h2>
                <p class="mb-3 text-[11px] font-medium text-[#84948a]">Berdasarkan genre yang mirip dengan tontonanmu</p>
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
        const URL_T = {{ (int) request()->query('t', 0) }};
        const HISTORY_KEY = 'biblioanime_history';

        const video = document.getElementById('player');
        const loadingEl = document.getElementById('playerLoading');
        const errorEl = document.getElementById('playerError');
        const errorText = document.getElementById('playerErrorText');
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
        const skipIntroBtn = document.getElementById('skipIntroBtn');
        const nextOverlay = document.getElementById('nextEpOverlay');

        let hls = null;
        let intro = null;
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
            errorText.textContent = msg || 'Stream belum tersedia untuk episode ini. Coba server lain.';
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

        function buildSubtitleOptions(subtitles) {
            video.querySelectorAll('track').forEach(t => t.remove());
            const menu = subtitleDropdown.menu;
            menu.innerHTML = '';
            const offBtn = document.createElement('button');
            offBtn.type = 'button';
            offBtn.className = 'pill-option';
            offBtn.dataset.value = '-1';
            offBtn.textContent = 'Nonaktif';
            menu.appendChild(offBtn);
            subtitles.forEach((sub, i) => {
                const track = document.createElement('track');
                track.kind = 'subtitles';
                track.label = sub.label;
                track.srclang = langCode(sub, i);
                track.src = sub.url;
                video.appendChild(track);
                const opt = document.createElement('button');
                opt.type = 'button';
                opt.className = 'pill-option';
                opt.dataset.value = String(i);
                opt.textContent = sub.label;
                menu.appendChild(opt);
            });
            // Default: Indonesia > Indonesia (Auto) > English > first available
            let def = -1;
            subtitles.forEach((sub, i) => {
                const raw = (sub.lang || '').toLowerCase();
                if (def === -1 && (raw === 'id' || raw.indexOf('indo') === 0)) def = i;
            });
            if (def === -1) subtitles.forEach((sub, i) => { if (def === -1 && (sub.lang || '').toLowerCase() === 'id-auto') def = i; });
            if (def === -1) subtitles.forEach((sub, i) => { if (def === -1 && langCode(sub, i) === 'en') def = i; });
            if (def === -1 && subtitles.length) def = 0;
            subtitleDropdown.setActive(def);
            applySubtitle(def);
        }

        function applySubtitle(idx) {
            const tracks = video.textTracks;
            for (let i = 0; i < tracks.length; i++) {
                tracks[i].mode = (i === idx) ? 'showing' : 'disabled';
            }
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
                if (!res.ok || !data.source) throw new Error(data.error || 'Stream tidak tersedia');
            } catch (e) {
                if (!tryAutoFailover()) {
                    showError('Semua server sudah dicoba otomatis, belum ada yang bisa memutar episode ini. Coba lagi nanti.');
                }
                return;
            }
            intro = data.intro || null;
            buildSubtitleOptions(data.subtitles || []);
            attachSource(data.source.url);
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
                hls = new Hls({ maxBufferLength: 30, startLevel: -1, capLevelToPlayerSize: true });
                hls.loadSource(src);
                hls.attachMedia(video);
                hls.on(Hls.Events.MANIFEST_PARSED, function () {
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
                        showError('Stream terputus dari sumber dan semua server cadangan sudah dicoba otomatis.');
                    }
                });
            } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                qualityDropdown.btn.disabled = true;
                qualityDropdown.btn.title = 'Kualitas dikelola otomatis oleh browser';
                video.src = src;
            } else {
                showError('Browser tidak mendukung pemutaran HLS.');
                return;
            }
            video.play().catch(function () {});
        }

        video.addEventListener('loadedmetadata', function () {
            loadingEl.style.display = 'none';
            if (!resumeApplied) {
                const target = storedResume();
                if (target > 0 && video.duration && target < video.duration - 10) {
                    video.currentTime = target;
                }
                resumeApplied = true;
            }
        });
        video.addEventListener('timeupdate', function () {
            const now = Date.now();
            if (now - lastSavedAt >= 5000) {
                lastSavedAt = now;
                saveProgress(false);
            }
            if (intro && video.currentTime >= intro.start && video.currentTime < intro.end) {
                skipIntroBtn.classList.remove('hidden');
            } else {
                skipIntroBtn.classList.add('hidden');
            }
            if (video.duration && video.currentTime > video.duration - 15) {
                const nxt = document.getElementById('nextEpBtn');
                if (nxt && nxt.href && !nxt.classList.contains('hidden')) {
                    nextOverlay.href = nxt.href;
                    nextOverlay.classList.remove('hidden');
                    nextOverlay.classList.add('flex');
                }
            }
        });
        video.addEventListener('pause', () => saveProgress(false));
        video.addEventListener('ended', () => saveProgress(true));
        document.addEventListener('visibilitychange', function () { if (document.hidden) saveProgress(false); });
        window.addEventListener('pagehide', () => saveProgress(false));
        skipIntroBtn.addEventListener('click', function () { if (intro) video.currentTime = intro.end; });

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
                    listEl.innerHTML = '<p class="py-6 text-center text-xs font-medium text-[#84948a]">Episode belum tersedia.</p>';
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
                }
            } catch (e) {
                listEl.innerHTML = '<p class="py-6 text-center text-xs font-medium text-[#84948a]">Daftar episode gagal dimuat.</p>';
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
                    recoEl.innerHTML = '<p class="py-4 text-center text-xs font-medium text-[#84948a]">Belum ada rekomendasi.</p>';
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
                recoEl.innerHTML = '<p class="py-4 text-center text-xs font-medium text-[#84948a]">Rekomendasi tidak tersedia saat ini.</p>';
            }
        }, 1200);

        loadStream();
    })();
    </script>
</x-manga-layout>
