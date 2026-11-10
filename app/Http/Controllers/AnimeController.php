<?php

namespace App\Http\Controllers;

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
    public function __construct(
        protected AnimeService $animeService,
        protected AnimeStreamProvider $streamProvider,
        protected SubtitleTranslator $subtitleTranslator,
        protected SubtitleLanguageDetector $subtitleLanguageDetector,
    ) {}

    /** Instant live search for the header search bar (anime mode). */
    public function liveSearch(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = trim((string) $request->input('q', ''));
        if (strlen($query) < 2) {
            return response()->json(['data' => []])
                ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=3600');
        }

        $results = Cache::remember('anime_live_search_' . md5(strtolower($query)), 3600, function () use ($query) {
            $res = $this->animeService->search($query, 1, 8);
            return $res['data'] ?? [];
        });

        return response()->json(['data' => array_slice($results, 0, 8)])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=3600');
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
            'seasonPopular' => $sections['season'] ?? [],
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

        return response()->view('anime.watch', [
            'anime' => $anime,
            'episodeId' => $episodeId,
            'episodeNumber' => max(1, (int) $request->query('n', 1)),
            'audio' => $request->query('audio') === 'dub' ? 'dub' : 'sub',
            'dubAvailable' => $this->streamProvider->dubAvailable((int) preg_replace('/[^0-9]/', '', $id)),
            'streamUrl' => route('anime.stream.json', ['id' => $id]) . '?ep=' . urlencode($episodeId),
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

        // Automatic playable-source search: a provider API returning a URL
        // does not prove the CDN actually serves it. Verify the playlist is
        // fetchable (and really is an M3U8) before handing it to the player;
        // if not, automatically try the next provider (max 4 candidates).
        $stream = null;
        $candidates = [];
        if ($server !== null) {
            $candidates[] = $server;
        }
        $candidates[] = null; // the episode's own provider
        foreach ($this->streamProvider->servers() as $name) {
            if (!in_array($name, $candidates, true)) {
                $candidates[] = $name;
            }
        }
        // An explicitly requested server is only verified, never silently
        // swapped: if it plays, it is served as-is.
        if ($server !== null) {
            try {
                $explicit = $this->streamProvider->getStream($episodeId, $server);
            } catch (\Throwable $e) {
                Log::error("streamJson failed for {$episodeId}: " . $e->getMessage());
                $explicit = null;
            }
            if ($explicit && !empty($explicit['sources'])) {
                $vKey = 'anime_verified_' . md5($episodeId . '|' . ($explicit['provider'] ?? $server));
                if (Cache::get($vKey)) {
                    $stream = $explicit;
                } else {
                    $check = $this->fetchRemote($explicit['sources'][0]['url'], $explicit['referer'] ?? null);
                    if ($check !== null && str_contains($check, '#EXTM3U')) {
                        Cache::put($vKey, 1, 3600);
                        $stream = $explicit;
                    }
                }
            }
        }
        // Automatic mode: collect up to 3 candidate streams, then serve the
        // one whose playlist downloads fastest (parallel speed test). The
        // same host serves the segments, so playlist speed predicts segment
        // speed; the default provider's CDN measured ~1.5s per segment.
        if (!$stream) {
            $collected = [];
            foreach ($candidates as $candidate) {
                if ($candidate !== null && $candidate === $server) {
                    continue; // already tried above
                }
                if (count($collected) >= 3) {
                    break;
                }
                try {
                    $tryStream = $this->streamProvider->getStream($episodeId, $candidate);
                } catch (\Throwable $e) {
                    Log::error("streamJson failed for {$episodeId}: " . $e->getMessage());
                    continue;
                }
                if (!$tryStream || empty($tryStream['sources'])) {
                    continue;
                }
                // Verified within the last hour: serve immediately, no test.
                $vKey = 'anime_verified_' . md5($episodeId . '|' . ($tryStream['provider'] ?? $candidate ?? 'default'));
                if (Cache::get($vKey)) {
                    $stream = $tryStream;
                    break;
                }
                $collected[] = $tryStream;
            }
            if (!$stream && $collected) {
                $winner = $this->fastestPlayable($collected);
                if ($winner) {
                    $stream = $winner;
                    Cache::put('anime_verified_' . md5($episodeId . '|' . ($stream['provider'] ?? 'default')), 1, 3600);
                }
            }
        }

        if (!$stream || empty($stream['sources'])) {
            return response()->json([
                'error' => 'Stream is not available for this episode. Try another server or title.',
                'servers' => $this->streamProvider->servers(),
            ], 404)->header('Cache-Control', 'no-store');
        }

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
            $subtitles[] = [
                'lang' => $sub['lang'],
                'label' => $sub['label'],
                'url' => route('anime.proxy.subtitle', ['k' => $proxyKey, 'idx' => $i]),
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
                $subtitles[] = [
                    'lang' => 'en',
                    'label' => 'English (Auto)',
                    'url' => route('anime.subtitle.en-auto', ['k' => $proxyKey, 'idx' => $sourceIdx]),
                ];
            }
        }


        return response()->json([
            'source' => [
                'url' => route('anime.proxy.playlist', ['k' => $proxyKey]),
                'quality' => $stream['sources'][0]['quality'] ?? 'auto',
            ],
            'subtitles' => $subtitles,
            'servers' => $this->streamProvider->servers(),
            'activeServer' => $stream['provider'] ?? $server,
            'provider' => $stream['provider'] ?? null,
            'intro' => $stream['intro'],
            'outro' => $stream['outro'],
        ])->header('Cache-Control', 'public, max-age=300');
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

    /** Playlist proxy: rewrites nested playlists through us, segments stay on the CDN. */
    public function playlistProxy(Request $request): Response
    {
        $key = (string) $request->query('k', '');
        $entry = Cache::get('anime_proxy_' . $key);
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
        $lines = explode("\n", $content);
        foreach ($lines as &$line) {
            $trim = trim($line);
            if ($trim === '') {
                continue;
            }
            if ($trim[0] !== '#') {
                $absolute = $this->resolveUrl($base, $trim);
                if (str_contains($absolute, '.m3u8')) {
                    $subKey = md5($absolute);
                    Cache::put('anime_proxy_' . $subKey, [
                        'playlist' => $absolute,
                        'referer' => $entry['referer'] ?? null,
                        'subtitles' => [],
                    ], 900);
                    $line = route('anime.proxy.playlist', ['k' => $subKey]);
                } else {
                    // Segments MUST go through our segment proxy: the CDN
                    // locks Access-Control-Allow-Origin to its own site, so
                    // browsers reject direct segment fetches (CORS) even
                    // though servers/curl see a plain 200.
                    $line = $this->segmentProxyUrl($absolute, $entry['referer'] ?? null);
                }
            } elseif (str_contains($trim, 'URI="')) {
                $line = preg_replace_callback('/URI="([^"]+)"/', function ($m) use ($base, $entry) {
                    $absolute = $this->resolveUrl($base, $m[1]);
                    if (str_contains($absolute, '.m3u8')) {
                        $subKey = md5($absolute);
                        Cache::put('anime_proxy_' . $subKey, [
                            'playlist' => $absolute,
                            'referer' => $entry['referer'] ?? null,
                            'subtitles' => [],
                        ], 900);
                        return 'URI="' . route('anime.proxy.playlist', ['k' => $subKey]) . '"';
                    }
                    return 'URI="' . $this->segmentProxyUrl($absolute, $entry['referer'] ?? null) . '"';
                }, $line);
            }
        }
        unset($line);

        return response(implode("\n", $lines))
            ->header('Content-Type', 'application/vnd.apple.mpegurl')
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=600');
    }

    /** Register a segment/key URL in the server-side registry and return its proxy URL. */
    protected function segmentProxyUrl(string $absolute, ?string $referer): string
    {
        $segKey = md5($absolute);
        Cache::put('anime_segment_' . $segKey, [
            'url' => $absolute,
            'referer' => $referer,
        ], 86400);

        return route('anime.proxy.segment', ['k' => $segKey]);
    }

    /**
     * Segment/key proxy. Required for playback: the video CDN sends
     * Access-Control-Allow-Origin locked to its own site, so a browser
     * (unlike curl) is CORS-blocked from fetching segments directly.
     * Only URLs registered server-side by playlistProxy are served.
     */
    public function segmentProxy(Request $request): Response
    {
        $key = (string) $request->query('k', '');
        $entry = Cache::get('anime_segment_' . $key);
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

    /** Subtitle proxy (same-origin VTT for the player track elements). */
    public function subtitleProxy(Request $request): Response
    {
        $key = (string) $request->query('k', '');
        $idx = (int) $request->query('idx', -1);
        $entry = Cache::get('anime_proxy_' . $key);
        $url = $entry['subtitles'][$idx] ?? null;
        if (!$url) {
            abort(404, 'Subtitle not found.');
        }

        $content = $this->fetchRemote($url, $entry['referer'] ?? null);
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
        $episodeId = (string) $request->query('ep', '');
        if ($episodeId === '') {
            abort(400, 'Invalid episode');
        }

        $stream = $this->streamProvider->getStream($episodeId);
        $englishUrl = null;
        foreach (($stream['subtitles'] ?? []) as $sub) {
            $langLower = strtolower($sub['lang']);
            if (str_starts_with($langLower, 'english') || $langLower === 'en') {
                $englishUrl = $sub['url'];
                break;
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
        $key = (string) $request->query('k', '');
        $idx = (int) $request->query('idx', -1);
        $entry = Cache::get('anime_proxy_' . $key);
        $url = $entry['subtitles'][$idx] ?? null;
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

        $headers = ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'];
        if ($referer) {
            $headers[] = 'Referer: ' . $referer;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($res === false || $code >= 400) ? null : $res;
    }
}
