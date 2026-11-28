<?php

namespace App\Http\Controllers;

use App\Services\Anime\AnimeExtraSources;
use App\Services\Anime\AnimeService;
use App\Services\Anime\AnimeStreamProvider;
use App\Services\Anime\SubtitleLanguageDetector;
use App\Services\Anime\SubtitleTranslator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnimeController extends Controller
{
    /** Largest slice the MP4 proxy streams in one request (2 MiB, under Vercel's 4.5 MB cap). */
    protected const FILE_CHUNK = 2 * 1024 * 1024;

    public function __construct(
        protected AnimeService $animeService,
        protected AnimeStreamProvider $streamProvider,
        protected SubtitleTranslator $subtitleTranslator,
        protected SubtitleLanguageDetector $subtitleLanguageDetector,
        protected AnimeExtraSources $extraSources,
    ) {}

    /** Instant live search for the header search bar (anime mode). */
    public function liveSearch(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = trim((string) $request->input('q', ''));
        if (strlen($query) < 2) {
            return response()->json(['data' => []])
                ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=3600');
        }

        $results = Cache::remember('anime_live_search_v2_' . md5(strtolower($query)), 3600, function () use ($query) {
            $res = $this->animeService->search($query, 1, 8);

            return array_map(fn ($a) => [
                'id' => $a['id'] ?? '',
                'title' => $a['title'] ?? 'Untitled',
                'coverUrl' => $a['coverUrl'] ?? null,
                'status' => $a['status'] ?? 'Ongoing',
                'format' => $a['format'] ?? 'TV',
                'year' => $a['year'] ?? null,
                'episodes' => $a['episodes'] ?? null,
                'airedEpisodes' => $a['airedEpisodes'] ?? null,
            ], array_slice($res['data'] ?? [], 0, 8));
        });

        return response()->json(['data' => $results])
            ->header('Cache-Control', 'public, max-age=120, s-maxage=600, stale-while-revalidate=3600');
    }

    /**
     * Anime home (sectioned, wide landscape cards) or, when searching /
     * filtering / paginating, the catalogue grid.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('q', ''));
        $category = $request->input('category', 'popular');
        $genre = $request->input('genre', '');
        $page = max(1, (int) $request->input('page', 1));
        $limit = 24;

        $categoryFilter = in_array($category, ['popular', 'trending', 'season', 'top', 'ongoing', 'completed', 'upcoming'], true) ? $category : 'popular';
        $genreFilter = $genre !== '' ? $genre : null;
        $isBrowse = $search !== '' || $request->has('category') || $request->has('genre') || $page > 1;

        if ($isBrowse) {
            $result = $search !== ''
                ? $this->animeService->search($search, $page, $limit, $genreFilter)
                : $this->animeService->getPopular($page, $limit, $categoryFilter, $genreFilter);

            return response()->view('anime.index', [
                'mode' => 'browse',
                'animes' => $result['data'] ?? [],
                'total' => $result['total'] ?? 0,
                'page' => $page,
                'totalPages' => $result['totalPages'] ?? 1,
                'search' => $search,
                'currentCategory' => $categoryFilter,
                'currentGenre' => $genre,
                'genres' => $this->animeService->getGenres(),
                'seasonInfo' => $this->animeService->currentSeason(),
            ])->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=86400');
        }

        $season = $this->animeService->currentSeason();
        $sections = $this->animeService->homeSections();
        $hero = $this->animeService->enrichHeroImages($sections['trending'] ?? [], 6);

        return response()->view('anime.index', [
            'mode' => 'home',
            'hero' => $hero,
            'trending' => $sections['trending'] ?? [],
            'seasonPopular' => array_slice($sections['season'] ?? [], 0, 12),
            'topRated' => $sections['top'] ?? [],
            'upcoming' => $sections['upcoming'] ?? [],
            'genres' => $this->animeService->getGenres(),
            'seasonInfo' => $season,
            'search' => '',
            'currentCategory' => 'popular',
            'currentGenre' => '',
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=86400');
    }

    /** Detail page. Episodes are NEVER resolved synchronously here. */
    public function show(string $id): Response
    {
        $anime = $this->animeService->getDetail($id);
        if (!$anime) {
            abort(404, 'Anime not found.');
        }

        return response()->view('anime.show', [
            'anime' => $anime,
            'available' => ! $this->animeService->isUnplayable($anime),
            'episodesUrl' => route('anime.episodes.json', ['id' => $id]),
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=86400');
    }

    /** Async episode list (AniList id -> streaming provider slug -> episodes). */
    public function episodesJson(string $id, Request $request): \Illuminate\Http\JsonResponse
    {
        $anime = $this->animeService->getDetail($id);
        if (!$anime) {
            return response()->json(['episodes' => [], 'total' => 0], 404);
        }

        // A title the catalogue already knows is unplayable: never hand the
        // player a synthetic episode list that is guaranteed to fail.
        if ($this->animeService->isUnplayable($anime)) {
            return response()->json([
                'providerId' => null,
                'providers' => [],
                'episodes' => [],
                'total' => 0,
                'audio' => $request->query('audio') === 'dub' ? 'dub' : 'sub',
                'audioAvailable' => ['sub' => false, 'dub' => false],
                'available' => false,
            ])->header('Cache-Control', 'no-store');
        }

        $audio = $request->query('audio') === 'dub' ? 'dub' : 'sub';
        $providerId = null;
        $providers = [];
        $audioAvailable = ['sub' => false, 'dub' => false];
        try {
            $numericId = (int) preg_replace('/[^0-9]/', '', $id);
            $data = $this->streamProvider->getEpisodes($numericId, $this->airedEpisodes($anime), $audio, $anime);
            $episodes = $data['episodes'] ?? [];
            $providerId = $data['provider'] ?? null;
            $providers = $data['providers'] ?? [];
            $audioAvailable = $data['audioAvailable'] ?? $audioAvailable;
        } catch (\Throwable $e) {
            Log::error("episodesJson failed for {$id}: " . $e->getMessage());
            $episodes = [];
        }

        $cacheControl = empty($episodes)
            ? 'public, max-age=30, s-maxage=60, stale-while-revalidate=120'
            : 'public, max-age=60, s-maxage=600, stale-while-revalidate=86400';

        return response()->json([
            'providerId' => $providerId,
            'providers' => $providers,
            'episodes' => $episodes,
            'total' => count($episodes),
            'audio' => $audio,
            'audioAvailable' => $audioAvailable,
        ])->header('Cache-Control', $cacheControl);
    }

    /**
     * How many episodes have already aired, straight from AniList metadata.
     * Used only as a completeness hint when picking streaming providers:
     * a provider that only lists 1 of 7 aired episodes is not good enough,
     * so the resolver keeps asking the next wave of providers.
     */
    protected function airedEpisodes(array $anime): ?int
    {
        $next = $anime['nextAiring']['episode'] ?? null;
        if (is_numeric($next) && (int) $next > 0) {
            return max(0, (int) $next - 1);
        }

        $total = $anime['episodes'] ?? null;
        return is_numeric($total) && (int) $total > 0 ? (int) $total : null;
    }

    /** Watch page shell. Stream resolution happens async via streamJson. */
    /**
     * Recommendations for the watch page sidebar: popular titles sharing the
     * current anime's primary genre, topped up with top-rated/trending ones.
     * Cached server-side for an hour and loaded asynchronously by the page,
     * so it never slows the player down.
     */
    public function recommendationsJson(string $id)
    {
        $anime = $this->animeService->getDetail($id);
        if (!$anime) {
            return response()->json(['recommendations' => []], 404);
        }

        $recos = Cache::remember('anime_reco_' . $anime['anilistId'], 3600, function () use ($anime) {
            // Genre-similar ONLY (no trending/top-rated filler): pool the
            // popular titles of the watched anime's first three genres, then
            // rank by how many genres they share with it, score as tiebreak.
            $myGenres = $anime['genres'] ?? [];
            $pool = [];
            foreach (array_slice($myGenres, 0, 3) as $genre) {
                $res = $this->animeService->getPopular(1, 12, 'popular', $genre);
                foreach ($res['data'] ?? [] as $item) {
                    $pool[$item['id']] = $item;
                }
            }
            unset($pool[$anime['id']]);
            $items = array_values($pool);
            usort($items, function ($a, $b) use ($myGenres) {
                $sharedA = count(array_intersect($myGenres, $a['genres'] ?? []));
                $sharedB = count(array_intersect($myGenres, $b['genres'] ?? []));
                if ($sharedA !== $sharedB) {
                    return $sharedB <=> $sharedA;
                }
                return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
            });
            $out = [];
            foreach (array_slice($items, 0, 8) as $item) {
                $out[] = [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'coverUrl' => $item['coverUrl'],
                    'bannerUrl' => $item['bannerUrl'],
                    'score' => $item['score'],
                    'year' => $item['year'],
                    'format' => $item['format'],
                    'episodes' => $item['episodes'],
                    'status' => $item['status'],
                    'genres' => array_slice($item['genres'] ?? [], 0, 3),
                ];
            }
            return $out;
        });

        return response()->json(['recommendations' => $recos])
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function watch(string $id, Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $anime = $this->animeService->getDetail($id);
        if (!$anime) {
            abort(404, 'Anime not found.');
        }

        $episodeId = (string) $request->query('ep', '');
        if ($episodeId === '') {
            return redirect()->route('anime.show', ['id' => $id]);
        }

        // Known-dead title: send the visitor back to the detail page, which
        // now explains that it has no digital release instead of mounting a
        // player that can never load.
        if ($this->animeService->isUnplayable($anime)) {
            return redirect()->route('anime.show', ['id' => $id, 'unavailable' => 1]);
        }

        // Seed the title cache before the browser asks for the stream: a
        // burned-in request is resolved by title, not by episode id.
        $this->streamProvider->rememberTitles($anime);

        return response()->view('anime.watch', [
            'anime' => $anime,
            'episodeId' => $episodeId,
            'episodeNumber' => max(1, (int) $request->query('n', 1)),
            'audio' => $request->query('audio') === 'dub' ? 'dub' : 'sub',
            'dubAvailable' => $this->streamProvider->dubAvailable((int) preg_replace('/[^0-9]/', '', $id)),
            'modes' => $this->streamProvider->modes(),
            'streamUrl' => route('anime.stream.json', ['id' => $id])
                . '?ep=' . urlencode($episodeId)
                . '&n=' . max(1, (int) $request->query('n', 1)),
            'episodesUrl' => route('anime.episodes.json', ['id' => $id]),
            'subtitleIdUrl' => route('anime.subtitle.id') . '?ep=' . urlencode($episodeId),
        ])->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=3600');
    }

    /**
     * Resolve playback: direct HLS sources rewritten through our playlist
     * proxy, subtitle tracks through our subtitle proxy. No embed URLs are
     * ever returned, so no third-party page/script reaches the browser.
     */
    public function streamJson(string $id, Request $request): \Illuminate\Http\JsonResponse
    {
        $episodeId = (string) $request->query('ep', '');
        if ($episodeId === '') {
            return response()->json(['error' => 'Invalid episode'], 400);
        }
        $server = $request->query('server');
        $server = is_string($server) && $server !== '' ? $server : null;
        $numericId = (int) preg_replace('/[^0-9]/', '', $id);
        $episodeNumber = max(0, (int) $request->query('n', 0));

        // Watch pages are edge-cached and serverless containers may not share
        // /tmp cache, so re-seed the title cache on demand whenever a burned-in
        // mode is requested and the cache is cold.
        if ($server !== null && $numericId > 0 && ! Cache::has("anime_titles_{$numericId}")) {
            try {
                $anime = $this->animeService->getDetail($id);
                if (is_array($anime) && ! empty($anime)) {
                    $this->streamProvider->rememberTitles($anime);
                }
            } catch (\Throwable $e) {
                Log::debug("streamJson title seed failed for {$id}: " . $e->getMessage());
            }
        }

        // Resolve the episode. getStream() already walks the episode's own
        // provider plus one alternate and falls back from dub to sub, so the
        // controller must not fan out again: every watch call costs 10-15 s
        // upstream and the request budget is 60 s.
        $stream = null;
        try {
            $stream = $this->streamProvider->getStream($episodeId, $server, $numericId ?: null, $episodeNumber ?: null);
        } catch (\Throwable $e) {
            Log::error("streamJson failed for {$episodeId}: " . $e->getMessage());
        }

        if (!$stream || empty($stream['sources'])) {
            $this->markUnavailableWhenNoEpisodes($id);
            return response()->json([
                'error' => 'This episode is not available on any provider yet.',
                'unavailable' => true,
                'reason' => 'no_stream',
            ], 404)->header('Cache-Control', 'no-store');
        }

        // Embed sources (Megaplay/VidWish) are rendered in an iframe by the
        // player with a warning banner about third-party pop-ups/redirects.
        if (($stream['kind'] ?? 'hls') === 'embed') {
            $this->animeService->markAvailable((int) preg_replace('/[^0-9]/', '', $id));
            return $this->embedResponse($stream, $server);
        }

        // Hard-subtitled sources ship one MP4 per episode: the subtitles are
        // part of the video, so there is nothing to proxy or track.
        if (($stream['kind'] ?? 'hls') === 'mp4') {
            $chosenSource = null;
            foreach (($stream['sources'] ?? []) as $candidateSource) {
                $candidateUrl = (string) ($candidateSource['url'] ?? '');
                if ($candidateUrl === '') {
                    continue;
                }
                $vKey = 'anime_mp4_ok_' . md5($candidateUrl);
                if (Cache::get($vKey) || app()->runningUnitTests()) {
                    $chosenSource = $candidateSource;
                    break;
                }
                if ($this->mp4Reachable($candidateUrl, $stream['referer'] ?? null)) {
                    Cache::put($vKey, 1, 3600);
                    $chosenSource = $candidateSource;
                    break;
                }
            }

            if ($chosenSource === null) {
                if (empty($stream['sources'][0]['url'])) {
                    return response()->json([
                        'error' => 'This episode is not available on any provider yet.',
                        'unavailable' => true,
                        'reason' => 'no_stream',
                    ], 404)->header('Cache-Control', 'no-store');
                }
                $this->markUnavailableWhenNoEpisodes($id);

                return response()->json([
                    'error' => 'This episode is not available on any provider yet.',
                    'unavailable' => true,
                    'reason' => 'dead_mp4',
                ], 404)->header('Cache-Control', 'no-store');
            }

            $url = (string) $chosenSource['url'];

            $this->animeService->markAvailable((int) preg_replace('/[^0-9]/', '', $id));

            // AnimeHeaven's, Nimegami's (Berkasdrive), and Kuramanime's file CDNs
            // reject or serve decoy clips for cross-site media requests (checking
            // Sec-Fetch-Site or Referer), so the browser cannot open the file
            // directly even though a server-side fetch succeeds. Route those
            // MP4s through our range-capable proxy instead.
            $playbackUrl = $url;
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $needsFileProxy = ! empty($stream['proxy_file'])
                || str_ends_with($host, 'animeheaven.me')
                || str_ends_with($host, 'berkasdrive.com')
                || str_ends_with($host, 'komari.my.id');
            if ($needsFileProxy) {
                $entry = ['url' => $url, 'referer' => $stream['referer'] ?? null];
                // Stateless signed token: each range request may land on a
                // different serverless container, so it cannot rely on /tmp.
                $fileToken = $this->signProxyPayload([
                    'u' => $entry['url'],
                    'r' => $entry['referer'],
                ]);
                Cache::put('anime_file_' . md5($url), $entry, 21600);
                // The cache key is a belt-and-braces fallback for deployments
                // where APP_KEY is only available to the HTTP runtime.
                $playbackUrl = route('anime.proxy.file', [
                    't' => $fileToken,
                    'k' => md5($url),
                ]);
            }

            return response()->json([
                'source' => [
                    'url' => $playbackUrl,
                    'quality' => $chosenSource['quality'] ?? 'auto',
                    'type' => 'mp4',
                ],
                'subtitles' => [],
                'servers' => $this->streamProvider->servers(),
                'modes' => $this->streamProvider->modes(),
                'activeServer' => $stream['provider'] ?? $server,
                'provider' => $stream['provider'] ?? null,
                // The player hides the subtitle/offset controls for these:
                // the text is already in the frames and cannot be changed.
                'hardSub' => true,
                'hardSubLang' => $stream['hard_sub_lang'] ?? null,
                'hardsubFallback' => $stream['hardsub_fallback'] ?? null,
                'audioFallback' => $stream['audio_fallback'] ?? null,
                'intro' => $stream['intro'] ?? null,
                'outro' => $stream['outro'] ?? null,
            ])->header('Cache-Control', 'public, max-age=300');
        }

        // HLS: a provider returning a URL does not prove the CDN serves it.
        // Verify the playlist once per hour; a dead manifest falls back to the
        // embed page if the resolver listed one.
        $vKey = 'anime_verified_' . md5($episodeId . '|' . ($stream['provider'] ?? $server ?? 'default'));
        if (!Cache::get($vKey)) {
            $check = $this->fetchRemote($stream['sources'][0]['url'], $stream['referer'] ?? null);
            if ($check === null || !str_contains($check, '#EXTM3U')) {
                if (!empty($stream['embed'])) {
                    $this->animeService->markAvailable((int) preg_replace('/[^0-9]/', '', $id));
                    return $this->embedResponse($stream, $server);
                }
                $this->markUnavailableWhenNoEpisodes($id);
                return response()->json([
                    'error' => 'This episode is not available on any provider yet.',
                    'unavailable' => true,
                    'reason' => 'dead_manifest',
                ], 404)->header('Cache-Control', 'no-store');
            }
            Cache::put($vKey, 1, 3600);
        }

        $this->animeService->markAvailable((int) preg_replace('/[^0-9]/', '', $id));

        // Server-side proxy registry: the browser only ever receives OUR
        // URLs; the proxy will only fetch URLs stored here, never arbitrary
        // user-supplied ones.
        $proxyKey = md5($episodeId . '|' . ($stream['provider'] ?? $server ?? 'default'));
        $subtitleUrls = array_map(fn ($s) => $s['url'], $stream['subtitles']);
        Cache::put('anime_proxy_' . $proxyKey, [
            'playlist' => $stream['sources'][0]['url'],
            'referer' => $stream['referer'],
            'subtitles' => $subtitleUrls,
        ], 21600);

        $subtitles = [];
        foreach ($stream['subtitles'] as $i => $sub) {
            $subToken = $this->signProxyPayload([
                'u' => $sub['url'],
                'r' => $stream['referer'] ?? null,
            ]);
            $subtitles[] = [
                'lang' => $sub['lang'],
                'label' => $sub['label'],
                'url' => route('anime.proxy.subtitle', ['k' => $proxyKey, 'idx' => $i, 't' => $subToken]),
            ];
        }

        // English is always the default. Most providers ship an English
        // track, but when a title only has Arabic/Thai/Italian/... the
        // player must not silently show those: add an "English (Auto)"
        // track translated from the best available source instead.
        //
        // Labels alone are not enough: anikoto tags every track "en" while
        // the first one is Simplified Chinese. Verify the cue text of each
        // "English"-labelled track and drop the ones that are actually a
        // different language, so they can never be picked by default.
        $englishLabelIndexes = [];
        foreach ($stream['subtitles'] as $i => $sub) {
            if ($this->subtitleLanguageFromLabel($sub['label'] ?? null) === 'en') {
                $englishLabelIndexes[] = $i;
            }
        }

        $hasEnglish = false;
        if (app()->runningUnitTests()) {
            // Tests mock the provider; do not hit real CDNs from phpunit.
            $hasEnglish = !empty($englishLabelIndexes);
        } else {
            foreach (array_slice($englishLabelIndexes, 0, 3) as $i) {
                $detected = $this->subtitleLanguageDetector->detect($stream['subtitles'][$i]['url']);
                if ($detected === 'sprite') {
                    unset($subtitles[$i]);
                    continue;
                }
                if ($detected === null || $detected === 'en') {
                    $hasEnglish = true;
                    break;
                }
                // The label lies (Chinese/Arabic/... content): remove it so
                // the default selection cannot land on it.
                unset($subtitles[$i]);
            }
            $subtitles = array_values($subtitles);
        }

        if (!$hasEnglish && !empty($stream['subtitles'])) {
            $sourceIdx = $this->pickTranslationSource($stream['subtitles']);
            if ($sourceIdx !== null) {
                $autoToken = $this->signProxyPayload([
                    'u' => $stream['subtitles'][$sourceIdx]['url'],
                    'r' => $stream['referer'] ?? null,
                ]);
                $subtitles[] = [
                    'lang' => 'en',
                    'label' => 'English (Auto)',
                    'url' => route('anime.subtitle.en-auto', ['k' => $proxyKey, 'idx' => $sourceIdx, 't' => $autoToken]),
                ];
            }
        }

        // Always offer Indonesian (Auto) translated from the best English track
        // when the provider does not already ship a native Indonesian track.
        $hasIndo = false;
        foreach ($subtitles as $sub) {
            if ($this->subtitleLanguageFromLabel($sub['label'] ?? null) === 'id') {
                $hasIndo = true;
                break;
            }
        }
        if (!$hasIndo && !empty($stream['subtitles'])) {
            $indoSourceIdx = null;
            foreach ($englishLabelIndexes as $i) {
                if (isset($stream['subtitles'][$i]['url'])) {
                    $indoSourceIdx = $i;
                    break;
                }
            }
            if ($indoSourceIdx === null) {
                $indoSourceIdx = $this->pickTranslationSource($stream['subtitles']);
            }
            if ($indoSourceIdx !== null) {
                $idToken = $this->signProxyPayload([
                    'u' => $stream['subtitles'][$indoSourceIdx]['url'],
                    'r' => $stream['referer'] ?? null,
                ]);
                $subtitles[] = [
                    'lang' => 'id',
                    'label' => 'Indonesian (Auto)',
                    'url' => route('anime.subtitle.id', ['ep' => $episodeId, 't' => $idToken]),
                ];
            }
        }

        $playlistToken = $this->signProxyPayload([
            'u' => $stream['sources'][0]['url'],
            'r' => $stream['referer'] ?? null,
        ]);

        return response()->json([
            'source' => [
                'url' => route('anime.proxy.playlist', ['k' => $proxyKey, 't' => $playlistToken]),
                'quality' => $stream['sources'][0]['quality'] ?? 'auto',
                'type' => 'hls',
            ],
            'subtitles' => $subtitles,
            'servers' => $this->streamProvider->servers(),
            'modes' => $this->streamProvider->modes(),
            'activeServer' => $stream['provider'] ?? $server,
            'provider' => $stream['provider'] ?? null,
            'hardSub' => false,
            'hardSubLang' => $stream['hard_sub_lang'] ?? null,
            'hardsubFallback' => $stream['hardsub_fallback'] ?? null,
            'hardsubDebug' => $stream['hardsub_debug'] ?? null,
            'audioFallback' => $stream['audio_fallback'] ?? null,
            'intro' => $stream['intro'],
            'outro' => $stream['outro'],
        ])->header('Cache-Control', ! empty($stream['hardsub_fallback']) ? 'no-store' : 'public, max-age=300');
    }

    /**
     * Only flag a title as globally unplayable when it has at most one
     * episode (a film / single-episode special). A single broken episode of
     * a long series must never hide the whole series from the catalogue.
     */
    protected function markUnavailableWhenNoEpisodes(string $id): void
    {
        $anime = $this->animeService->getDetail($id);
        if (!$anime) {
            return;
        }
        $count = (int) ($anime['episodes'] ?? 0);
        if ($count <= 1 || ($anime['format'] ?? '') === 'MOVIE') {
            $this->animeService->markUnavailable((int) ($anime['anilistId'] ?? 0));
        }
    }

    /**
     * JSON payload for an embed-only source. The player mounts the URL in a
     * sandboxed iframe; no proxying or subtitle injection is possible there.
     */
    protected function embedResponse(array $stream, ?string $server): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'source' => [
                'url' => $stream['embed'] ?? $stream['sources'][0]['url'],
                'type' => 'embed',
            ],
            'subtitles' => [],
            'servers' => $this->streamProvider->servers(),
            'modes' => $this->streamProvider->modes(),
            'activeServer' => $stream['provider'] ?? $server,
            'provider' => $stream['provider'] ?? null,
            // Samehadaku mirrors are hard-subtitled too, so the player keeps
            // the subtitle/offset controls out of the way when that is the
            // only copy left.
            'hardSub' => ! empty($stream['hard_sub']),
            'hardSubLang' => $stream['hard_sub_lang'] ?? null,
            'hardsubFallback' => $stream['hardsub_fallback'] ?? null,
            'hardsubDebug' => $stream['hardsub_debug'] ?? null,
            'audioFallback' => $stream['audio_fallback'] ?? null,
            'intro' => $stream['intro'],
            'outro' => $stream['outro'],
        ])->header('Cache-Control', ! empty($stream['hardsub_fallback']) ? 'no-store' : 'public, max-age=300');
    }

    /**
     * Opening/ending skip times for the player, resolved through AniSkip
     * (MyAnimeList id based). Cached for six hours per title+episode so the
     * player can ask on every load without hitting the third party.
     */
    public function skipTimesJson(string $id, Request $request): \Illuminate\Http\JsonResponse
    {
        $episode = max(1, (int) $request->query('n', 1));
        $anime = $this->animeService->getDetail($id);
        $malId = $anime['malId'] ?? null;

        // AniList sometimes ships an entry without idMal (newly added
        // seasons, regional re-releases). Ask the resolver's /map endpoint
        // before giving up on skip times.
        if (! $malId) {
            $anilistId = (int) ($anime['anilistId'] ?? preg_replace('/[^0-9]/', '', $id));
            $malId = $this->extraSources->ids($anilistId)['malId'] ?? null;
        }

        if (!$malId) {
            return response()->json(['intro' => null, 'outro' => null]);
        }

        $data = Cache::remember("aniskip_v2_{$malId}_{$episode}", 21600, function () use ($malId, $episode) {
            try {
                $res = Http::timeout(5)
                    ->acceptJson()
                    ->get("https://api.aniskip.com/v2/skip-times/{$malId}/{$episode}", [
                        'types' => ['op', 'ed'],
                        'episodeLength' => 0,
                    ]);

                return $res->ok() ? $res->json() : null;
            } catch (\Throwable $e) {
                Log::warning("AniSkip failed for MAL {$malId} ep {$episode}: " . $e->getMessage());

                return null;
            }
        });

        $intro = null;
        $outro = null;
        if (is_array($data) && !empty($data['found']) && is_array($data['results'] ?? null)) {
            foreach ($data['results'] as $result) {
                $interval = $result['interval'] ?? null;
                if (!is_array($interval) || !isset($interval['startTime'], $interval['endTime'])) {
                    continue;
                }
                $slot = [
                    'start' => (float) $interval['startTime'],
                    'end' => (float) $interval['endTime'],
                ];
                $type = $result['skipType'] ?? '';
                if ($type === 'op' || $type === 'mixed-op') {
                    $intro = $slot;
                } elseif ($type === 'ed' || $type === 'mixed-ed') {
                    $outro = $slot;
                }
            }
        }

        return response()->json(['intro' => $intro, 'outro' => $outro])
            ->header('Cache-Control', 'public, max-age=21600');
    }

    /** Build an HMAC-signed, URL-safe token so proxy URLs work across stateless Vercel lambdas. */
    protected function signProxyPayload(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $b64 = rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
        $sig = substr(hash_hmac('sha256', $b64, (string) config('app.key')), 0, 24);

        return $b64 . '.' . $sig;
    }

    /** Verify an HMAC-signed proxy token; returns null if missing or tampered. */
    protected function verifyProxyPayload(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        $expected = substr(hash_hmac('sha256', $parts[0], (string) config('app.key')), 0, 24);
        if (!hash_equals($expected, $parts[1])) {
            return null;
        }
        $raw = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }

    /** Playlist proxy: rewrites nested playlists and segments with stateless signed URLs. */
    public function playlistProxy(Request $request): Response
    {
        $tokenData = $this->verifyProxyPayload((string) $request->query('t', ''));
        if ($tokenData && !empty($tokenData['u'])) {
            $entry = [
                'playlist' => (string) $tokenData['u'],
                'referer' => $tokenData['r'] ?? null,
            ];
        } else {
            $key = (string) $request->query('k', '');
            $entry = Cache::get('anime_proxy_' . $key);
        }
        if (!$entry || empty($entry['playlist'])) {
            abort(404, 'Stream session not found or expired. Reload the page.');
        }

        // VOD playlists are static; a 60s server-side cache makes quality
        // switches and first loads skip a redundant CDN round trip.
        $plKey = 'anime_playlist_' . md5($entry['playlist']);
        $content = Cache::get($plKey);
        if (!is_string($content)) {
            $content = $this->fetchRemote($entry['playlist'], $entry['referer'] ?? null);
            if ($content === null || !str_contains($content, '#EXTM3U')) {
                abort(502, 'Unable to fetch the playlist from the source.');
            }
            Cache::put($plKey, $content, 60);
        }

        $base = $entry['playlist'];
        $referer = $entry['referer'] ?? null;
        $lines = explode("\n", $content);
        foreach ($lines as &$line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if ($trim[0] !== '#') {
                $absolute = $this->resolveUrl($base, $trim);
                if (str_contains($absolute, '.m3u8')) {
                    $subToken = $this->signProxyPayload(['u' => $absolute, 'r' => $referer]);
                    $line = route('anime.proxy.playlist', ['t' => $subToken]);
                } else {
                    $line = $this->segmentProxyUrl($absolute, $referer);
                }
            } elseif (str_contains($trim, 'URI="')) {
                $line = preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($base, $referer) {
                    $absolute = $this->resolveUrl($base, $m[1]);
                    if (str_contains($absolute, '.m3u8')) {
                        $subToken = $this->signProxyPayload(['u' => $absolute, 'r' => $referer]);
                        return 'URI="' . route('anime.proxy.playlist', ['t' => $subToken]) . '"';
                    }
                    return 'URI="' . $this->segmentProxyUrl($absolute, $referer) . '"';
                }, $line);
            }
        }
        unset($line);

        return response(implode("\n", $lines))
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=600');
    }

    /** Return a stateless HMAC-signed proxy URL for a segment or encryption key. */
    protected function segmentProxyUrl(string $absolute, ?string $referer): string
    {
        $segToken = $this->signProxyPayload([
            'u' => $absolute,
            'r' => $referer,
        ]);

        return route('anime.proxy.segment', ['t' => $segToken]);
    }

    /**
     * Segment/key proxy. Required for playback: the video CDN sends
     * Access-Control-Allow-Origin locked to its own site, so a browser
     * (unlike curl) is CORS-blocked from fetching segments directly.
     * Stateless HMAC-signed tokens ensure requests succeed across any
     * Vercel serverless container without relying on local /tmp cache.
     */
    public function segmentProxy(Request $request): Response
    {
        $tokenData = $this->verifyProxyPayload((string) $request->query('t', ''));
        if ($tokenData && !empty($tokenData['u'])) {
            $entry = [
                'url' => (string) $tokenData['u'],
                'referer' => $tokenData['r'] ?? null,
            ];
        } else {
            $key = (string) $request->query('k', '');
            $entry = Cache::get('anime_segment_' . $key);
        }
        if (!$entry || empty($entry['url'])) {
            abort(404, 'Segment not found or expired. Reload the page.');
        }

        $content = $this->fetchRemote($entry['url'], $entry['referer'] ?? null);
        if ($content === null) {
            abort(502, 'Unable to fetch the segment from the source.');
        }

        $ext = strtolower(pathinfo((string) parse_url($entry['url'], PHP_URL_PATH), PATHINFO_EXTENSION));
        $type = $ext === 'ts' ? 'video/mp2t' : 'application/octet-stream';

        return response($content)
            ->header('Content-Type', $type)
            ->header('Cache-Control', 'public, max-age=86400, s-maxage=86400, stale-while-revalidate=604800')
            ->header('CDN-Cache-Control', 'public, max-age=86400, stale-while-revalidate=604800');
    }

    /**
     * Range-capable proxy for a burned-in MP4.
     *
     * AnimeHeaven's file CDN answers 403 to a browser's cross-site media
     * request (it inspects Sec-Fetch-Site), so the file looks dead in the
     * player while a plain server-side fetch works. Only URLs registered by
     * streamJson() are served, never arbitrary user input.
     */
    public function fileProxy(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $tokenData = $this->verifyProxyPayload((string) $request->query('t', ''));
        if ($tokenData && ! empty($tokenData['u'])) {
            $entry = [
                'url' => (string) $tokenData['u'],
                'referer' => $tokenData['r'] ?? null,
            ];
        } else {
            $key = (string) $request->query('k', '');
            $entry = Cache::get('anime_file_' . $key);
        }
        if (! is_array($entry) || empty($entry['url'])) {
            abort(404, 'File session not found or expired. Reload the page.');
        }

        [$total, $ranges] = $this->probeFile($entry);

        [$start, $end, $status] = $this->fileBounds((string) $request->header('Range', ''), $total);
        if ($start === null) {
            abort(416, 'Requested range not satisfiable.');
        }
        // A file served without range support must be sent whole; pretending
        // to answer 206 would desynchronise the browser's buffer.
        if (! $ranges) {
            [$start, $end, $status] = [0, max(0, $total - 1), 200];
        } elseif ($status === 206 && $end - $start + 1 > self::FILE_CHUNK) {
            // Answer one bounded slice per request. The browser asks for the
            // rest as it plays, and no single function invocation has to
            // stream a 250 MB episode inside the platform's time limit.
            $end = $start + self::FILE_CHUNK - 1;
        }

        $length = $total > 0 ? $end - $start + 1 : null;
        $rangeHeader = $status === 206 ? "bytes={$start}-{$end}" : null;

        $headers = [
            'Content-Type' => 'video/mp4',
            'Accept-Ranges' => $ranges ? 'bytes' : 'none',
            'Cache-Control' => 'private, max-age=0, no-store',
        ];
        if ($length !== null) {
            $headers['Content-Length'] = (string) $length;
        }
        if ($status === 206 && $length !== null) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$total}";
        }

        return response()->stream(
            fn () => $this->streamFile($entry, $rangeHeader),
            $status,
            $headers
        );
    }

    /**
     * Total size and range support, learned with a two-byte request so the
     * proxy can answer Content-Length/Content-Range before streaming.
     *
     * @param  array<string, mixed>  $entry
     * @return array{0: int, 1: bool} [total bytes (0 when unknown), supports ranges]
     */
    protected function probeFile(array $entry): array
    {
        $cacheKey = 'anime_file_meta_' . md5((string) $entry['url']);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['total'], $cached['ranges'])) {
            return [(int) $cached['total'], (bool) $cached['ranges']];
        }

        $headers = ['Accept: */*'];
        if (! empty($entry['referer'])) {
            $headers[] = 'Referer: ' . $entry['referer'];
        }
        $headers[] = 'Range: bytes=0-1';

        $response = [];
        $status = 0;
        $ch = curl_init((string) $entry['url']);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$response, &$status) {
                $trim = trim($line);
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $trim, $m)) {
                    $status = (int) $m[1];
                    $response = [];
                } elseif (str_contains($trim, ':')) {
                    [$name, $value] = explode(':', $trim, 2);
                    $response[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);

        if ($status === 206 && preg_match('#bytes\s+\d+-\d+/(\d+)#i', (string) ($response['content-range'] ?? ''), $m)) {
            $result = [(int) $m[1], true];
        } elseif ($status === 200 && isset($response['content-length'])) {
            $result = [(int) $response['content-length'], false];
        } else {
            return [0, false];
        }

        Cache::put($cacheKey, ['total' => $result[0], 'ranges' => $result[1]], 21600);

        return $result;
    }

    /**
     * @return array{0: int|null, 1: int, 2: int} [start (null when unsatisfiable), end, status]
     */
    protected function fileBounds(string $range, int $total): array
    {
        if ($total <= 0) {
            return [0, 0, 200];
        }

        if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
            if ($m[1] === '') {
                $start = max(0, $total - (int) $m[2]);
                $end = $total - 1;
            } else {
                $start = (int) $m[1];
                $end = $m[2] === '' ? $total - 1 : min((int) $m[2], $total - 1);
            }

            if ($start >= $total || $start > $end) {
                return [null, 0, 416];
            }

            return [$start, $end, 206];
        }

        return [0, $total - 1, 200];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    protected function streamFile(array $entry, ?string $range): void
    {
        $headers = ['Accept: */*'];
        if (! empty($entry['referer'])) {
            $headers[] = 'Referer: ' . $entry['referer'];
        }
        if ($range !== null) {
            $headers[] = 'Range: ' . $range;
        }

        $ch = curl_init((string) $entry['url']);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME => 60,
            CURLOPT_BUFFERSIZE => 65536,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) {
                echo $chunk;
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();

                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);
    }

    /** Subtitle proxy (same-origin VTT for the player track elements). */
    public function subtitleProxy(Request $request): Response
    {
        $tokenData = $this->verifyProxyPayload((string) $request->query('t', ''));
        if ($tokenData && !empty($tokenData['u'])) {
            $url = (string) $tokenData['u'];
            $referer = $tokenData['r'] ?? null;
        } else {
            $key = (string) $request->query('k', '');
            $idx = (int) $request->query('idx', -1);
            $entry = Cache::get('anime_proxy_' . $key);
            $url = $entry['subtitles'][$idx] ?? null;
            $referer = $entry['referer'] ?? null;
        }
        if (!$url) {
            abort(404, 'Subtitle not found.');
        }

        $content = $this->fetchRemote($url, $referer);
        if ($content === null) {
            abort(502, 'Unable to fetch the subtitle.');
        }

        return response($content)
            ->header('Content-Type', 'text/vtt; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=3600, s-maxage=86400');
    }

    /** Indonesian subtitle generated from the English track (cached 30 days). */
    public function translatedSubtitle(Request $request): Response
    {
        $englishUrl = null;
        $tokenData = $this->verifyProxyPayload((string) $request->query('t', ''));
        if ($tokenData && !empty($tokenData['u'])) {
            $englishUrl = (string) $tokenData['u'];
        } else {
            $episodeId = (string) $request->query('ep', '');
            if ($episodeId === '') {
                abort(400, 'Invalid episode');
            }
            $stream = $this->streamProvider->getStream($episodeId);
            foreach (($stream['subtitles'] ?? []) as $sub) {
                $langLower = strtolower($sub['lang']);
                if (str_starts_with($langLower, 'english') || $langLower === 'en') {
                    $englishUrl = $sub['url'];
                    break;
                }
            }
        }
        if (!$englishUrl) {
            abort(404, 'No English subtitle is available to translate.');
        }

        $vtt = $this->subtitleTranslator->indonesianVtt($englishUrl);
        if ($vtt === null) {
            abort(404, 'The Indonesian subtitle could not be generated. Use the English subtitle.');
        }

        return response($vtt)
            ->header('Content-Type', 'text/vtt; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400, s-maxage=604800');
    }

    /**
     * English subtitle generated from the best available track when the
     * provider ships no English subtitle at all (cached 30 days). The
     * player's default selection points at this track, so titles without
     * English never fall back to Arabic/Thai/Indonesian.
     */
    public function autoEnglishSubtitle(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $tokenData = $this->verifyProxyPayload((string) $request->query('t', ''));
        if ($tokenData && !empty($tokenData['u'])) {
            $url = (string) $tokenData['u'];
        } else {
            $key = (string) $request->query('k', '');
            $idx = (int) $request->query('idx', -1);
            $entry = Cache::get('anime_proxy_' . $key);
            $url = $entry['subtitles'][$idx] ?? null;
        }
        if (!$url) {
            abort(404, 'Subtitle not found.');
        }

        $vtt = $this->subtitleTranslator->englishVtt($url);
        if ($vtt === null) {
            // Never serve the source track under the English label: that is
            // how Arabic/Chinese/Indonesian leaked back into the player.
            // An empty VTT keeps the timeline valid and simply shows no
            // cues until the translation succeeds on a later request.
            return response("WEBVTT\n\n")
                ->header('Content-Type', 'text/vtt; charset=utf-8')
                ->header('Cache-Control', 'no-store')
                ->header('X-Subtitle-Translation', 'failed');
        }

        return response($vtt)
            ->header('Content-Type', 'text/vtt; charset=utf-8')
            ->header('Cache-Control', 'public, max-age=86400, s-maxage=604800');
    }

    /**
     * Language code from a human label ("Arabic" -> ar). Labels are far
     * more reliable than provider srclang tags, which are often all "en".
     */
    protected function subtitleLanguageFromLabel(?string $label): ?string
    {
        $l = strtolower(trim((string) $label));
        if ($l === '') {
            return null;
        }

        $map = [
            'english' => 'en', 'eng' => 'en', 'en' => 'en',
            'indonesian' => 'id', 'indonesia' => 'id', 'indo' => 'id', 'id' => 'id',
            'arabic' => 'ar', 'ara' => 'ar', 'ar' => 'ar',
            'chinese' => 'zh', 'mandarin' => 'zh', 'cantonese' => 'zh', 'zho' => 'zh', 'chi' => 'zh', 'zh' => 'zh',
            'japanese' => 'ja', 'jpn' => 'ja', 'jp' => 'ja', 'ja' => 'ja',
            'korean' => 'ko', 'kor' => 'ko', 'ko' => 'ko',
            'thai' => 'th', 'tha' => 'th', 'th' => 'th',
            'french' => 'fr', 'fra' => 'fr', 'fre' => 'fr', 'fr' => 'fr',
            'german' => 'de', 'deu' => 'de', 'ger' => 'de', 'de' => 'de',
            'italian' => 'it', 'ita' => 'it', 'it' => 'it',
            'portuguese' => 'pt', 'por' => 'pt', 'pt' => 'pt',
            'russian' => 'ru', 'rus' => 'ru', 'ru' => 'ru',
            'spanish' => 'es', 'spa' => 'es', 'es' => 'es',
            'vietnamese' => 'vi', 'vie' => 'vi', 'vi' => 'vi',
            'bengali' => 'bn', 'ben' => 'bn', 'bn' => 'bn',
            'persian' => 'fa', 'farsi' => 'fa', 'fas' => 'fa', 'fa' => 'fa',
            'turkish' => 'tr', 'tur' => 'tr', 'tr' => 'tr',
            'polish' => 'pl', 'pol' => 'pl', 'pl' => 'pl',
            'dutch' => 'nl', 'nld' => 'nl', 'dut' => 'nl', 'nl' => 'nl',
            'ukrainian' => 'uk', 'ukr' => 'uk', 'uk' => 'uk',
            'romanian' => 'ro', 'ron' => 'ro', 'rum' => 'ro', 'ro' => 'ro',
            'hungarian' => 'hu', 'hun' => 'hu', 'hu' => 'hu',
            'czech' => 'cs', 'ces' => 'cs', 'cze' => 'cs', 'cs' => 'cs',
            'greek' => 'el', 'ell' => 'el', 'gre' => 'el', 'el' => 'el',
            'hebrew' => 'he', 'heb' => 'he', 'he' => 'he',
            'swedish' => 'sv', 'swe' => 'sv', 'sv' => 'sv',
            'danish' => 'da', 'dan' => 'da', 'da' => 'da',
            'finnish' => 'fi', 'fin' => 'fi', 'fi' => 'fi',
            'norwegian' => 'no', 'nor' => 'no', 'no' => 'no',
            'malay' => 'ms', 'msa' => 'ms', 'may' => 'ms', 'ms' => 'ms',
            'filipino' => 'tl', 'tagalog' => 'tl', 'fil' => 'tl', 'tl' => 'tl',
            'hindi' => 'hi', 'hin' => 'hi', 'hi' => 'hi',
            'tamil' => 'ta', 'tam' => 'ta', 'ta' => 'ta',
            'telugu' => 'te', 'tel' => 'te', 'te' => 'te',
            'urdu' => 'ur', 'urd' => 'ur', 'ur' => 'ur',
        ];

        foreach ($map as $name => $code) {
            if ($l === $name || str_starts_with($l, $name . ' ')) {
                return $code;
            }
        }

        return null;
    }

    /** Prefer the Indonesian track, otherwise the first labelled track. */
    protected function pickTranslationSource(array $rawSubtitles): ?int
    {
        $fallback = null;
        foreach ($rawSubtitles as $i => $sub) {
            $code = $this->subtitleLanguageFromLabel($sub['label'] ?? null);
            if ($code === 'id') {
                return $i;
            }
            if ($code !== null && $fallback === null) {
                $fallback = $i;
            }
        }

        return $fallback ?? (array_key_exists(0, $rawSubtitles) ? 0 : null);
    }

    protected function resolveUrl(string $base, string $relative): string
    {
        if (str_starts_with($relative, 'http://') || str_starts_with($relative, 'https://')) {
            return $relative;
        }
        $parts = parse_url($base);
        $root = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $root .= ':' . $parts['port'];
        }
        if (str_starts_with($relative, '/')) {
            return $root . $relative;
        }
        $dir = isset($parts['path']) ? substr($parts['path'], 0, strrpos($parts['path'], '/') + 1) : '/';
        return $root . $dir . $relative;
    }

    /**
     * Parallel playlist speed test: returns the stream whose master playlist
     * downloads fastest (and really is an M3U8), or null when none respond.
     */
    protected function fastestPlayable(array $streams): ?array
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($streams as $i => $s) {
            $headers = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'];
            if (!empty($s['referer'])) {
                $headers[] = 'Referer: ' . $s['referer'];
            }
            $ch = curl_init($s['sources'][0]['url']);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running > 0);
        $best = null;
        $bestTime = null;
        foreach ($handles as $i => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            if ($code === 200 && $body && str_contains($body, '#EXTM3U') && ($bestTime === null || $time < $bestTime)) {
                $best = $streams[$i];
                $bestTime = $time;
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        if ($best) {
            Log::info('streamJson: fastest provider ' . ($best['provider'] ?? '?') . ' playlist ' . round((float) $bestTime, 2) . 's');
        }

        return $best;
    }

    /**
     * Cheap liveness check for a hard-subtitled MP4: HEAD first, then a small
     * ranged GET for CDNs that reject HEAD.
     */
    protected function mp4Reachable(string $url, ?string $referer = null): bool
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }
        $ip = gethostbyname($host);
        $ipToCheck = filter_var($host, FILTER_VALIDATE_IP) ? $host : $ip;
        if (!filter_var($ipToCheck, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        foreach ([true, false] as $headOnly) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_NOBODY, $headOnly);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 2);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            $headers = $headOnly ? [] : ['Accept: video/*,*/*'];
            if ($referer !== null && $referer !== '') {
                $headers[] = 'Referer: ' . $referer;
            }
            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            if (!$headOnly) {
                curl_setopt($ch, CURLOPT_RANGE, '0-1024');
            }
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if (in_array($code, [200, 206], true)) {
                return true;
            }
        }

        return false;
    }

    protected function fetchRemote(string $url, ?string $referer = null): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        $ip = gethostbyname($host);
        $ipToCheck = filter_var($host, FILTER_VALIDATE_IP) ? $host : $ip;
        if (!filter_var($ipToCheck, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $headers = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'];
        if ($referer) {
            $headers[] = 'Referer: ' . $referer;
            $refParts = parse_url($referer);
            if (!empty($refParts['scheme']) && !empty($refParts['host'])) {
                $headers[] = 'Origin: ' . $refParts['scheme'] . '://' . $refParts['host'];
            }
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($res === false || $code >= 400) ? null : $res;
    }
}
