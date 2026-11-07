<?php

namespace App\Http\Controllers;

use App\Services\Manga\MangaService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MangaController extends Controller
{
    protected MangaService $mangaService;

    public function __construct(MangaService $mangaService)
    {
        $this->mangaService = $mangaService;
    }

    /**
     * Instant live search API with caching for debounced search bar.
     */
    public function liveSearch(Request $request): \Illuminate\Http\JsonResponse
    {
        $query = trim((string) $request->input('q', ''));
        if (strlen($query) < 2) {
            return response()
                ->json(['data' => []])
                ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=3600');
        }

        $cacheKey = 'live_search_'.md5(strtolower($query));
        $results = \Illuminate\Support\Facades\Cache::remember($cacheKey, 3600, function () use ($query) {
            $res = $this->mangaService->search($query, 1, 8);

            return $res['data'] ?? [];
        });

        return response()
            ->json(['data' => array_slice($results, 0, 8)])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=3600');
    }

    /**
     * Display the public manga catalogue / explore page.
     */
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('q', ''));
        $category = $request->input('category', 'popular'); // 'popular', 'trending', 'ongoing', 'completed'
        $genre = $request->input('genre', '');
        $page = max(1, (int) $request->input('page', 1));
        $limit = 24;

        $categoryFilter = in_array($category, ['popular', 'trending', 'ongoing', 'completed']) ? $category : 'popular';
        $genreFilter = $genre !== '' ? $genre : null;

        if ($search !== '') {
            $result = $this->mangaService->search($search, $page, $limit, $categoryFilter, $genreFilter);
        } else {
            $result = $this->mangaService->getPopular($page, $limit, $categoryFilter, $genreFilter);
        }

        $genres = $this->mangaService->getGenres();

        return response()
            ->view('manga.index', [
                'mangas' => $result['data'] ?? [],
                'total' => $result['total'] ?? 0,
                'page' => $page,
                'totalPages' => $result['totalPages'] ?? 1,
                'search' => $search,
                'currentCategory' => $categoryFilter,
                'currentGenre' => $genre,
                'genres' => $genres,
            ])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=86400');
    }

    /**
     * Display manga details.
     *
     * The chapter list is NEVER aggregated on this request. We only read it
     * from cache (instant when warm) and otherwise render a skeleton while
     * Alpine fetches /manga/{id}/chapters.json in the background. This turns
     * the old 7-9s first paint into a single cached AniList lookup.
     */
    public function show(string $id, Request $request): Response
    {
        $manga = $this->mangaService->getDetail($id);
        if (! $manga) {
            abort(404, 'Manga tidak ditemukan.');
        }

        $cachedChapters = $this->preloadChapters($id);
        $payload = $this->buildChapterPayload($cachedChapters, $id);

        return response()
            ->view('manga.show', [
                'manga' => $manga,
                'chapterPayload' => $payload,
                'chaptersPreloaded' => ! empty($cachedChapters),
                'chaptersUrl' => route('manga.chapters.json', ['id' => $id]),
                'activeTab' => $payload['counts']['id'] > 0 ? 'id' : ($payload['counts']['en'] > 0 ? 'en' : 'all'),
            ])
            ->header('Cache-Control', 'public, max-age=60, s-maxage=300, stale-while-revalidate=86400');
    }

    /**
     * Async chapter list endpoint. Runs the expensive aggregation only when
     * the shared cache is cold.
     */
    public function chaptersJson(string $id, Request $request): \Illuminate\Http\JsonResponse
    {
        $language = $request->query('lang');
        if (! in_array($language, ['id', 'en'], true)) {
            $language = null;
        }

        $failed = false;
        $partial = false;
        try {
            $chapters = $this->mangaService->getChapters($id, $language, $partial) ?: [];
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("chaptersJson failed for {$id}: ".$e->getMessage());
            $chapters = [];
            $failed = true;
        }

        $payload = $this->buildChapterPayload($chapters, $id);
        $payload['partial'] = $partial;

        // Do not let a transient upstream failure poison the edge cache, and
        // keep partial lists on a short TTL so the next request can enrich them.
        if ($failed || empty($chapters)) {
            $cacheControl = 'no-store';
        } elseif ($partial) {
            $cacheControl = 'public, max-age=10, s-maxage=30, stale-while-revalidate=30';
        } else {
            $cacheControl = 'public, max-age=300, s-maxage=43200, stale-while-revalidate=86400';
        }

        return response()
            ->json($payload)
            ->header('Cache-Control', $cacheControl);
    }

    /**
     * Warm the shared cache for the most popular manga. Intended to be called
     * by an external cron (Vercel Hobby only allows daily crons).
     */
    public function warm(Request $request): \Illuminate\Http\JsonResponse
    {
        $expected = (string) ($_ENV['WARM_TOKEN'] ?? $_SERVER['WARM_TOKEN'] ?? getenv('WARM_TOKEN') ?: '');
        if ($expected !== '' && ! hash_equals($expected, (string) $request->query('token', ''))) {
            abort(403, 'Invalid warm token.');
        }

        @set_time_limit(25);

        $limit = max(1, min(12, (int) $request->query('limit', 6)));
        $start = microtime(true);
        $deadline = $start + 20;
        $warmed = [];
        $failed = [];

        try {
            $popular = $this->mangaService->getPopular(1, $limit);
            $ids = array_values(array_filter(array_column($popular['data'] ?? [], 'id')));
        } catch (\Throwable $e) {
            $ids = [];
        }

        foreach ($ids as $id) {
            if (microtime(true) > $deadline) {
                break;
            }
            try {
                $chapters = $this->mangaService->getChapters($id);
                if (! empty($chapters)) {
                    $warmed[] = $id;
                } else {
                    $failed[] = $id;
                }
            } catch (\Throwable $e) {
                $failed[] = $id;
            }
        }

        return response()
            ->json([
                'warmed' => $warmed,
                'failed' => $failed,
                'elapsed' => round(microtime(true) - $start, 2),
            ])
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Read whatever chapter list is already cached without triggering any
     * network aggregation. Merges the per-language caches when the "all"
     * cache has not been built yet.
     */
    protected function preloadChapters(string $id): array
    {
        $all = Cache::get("aggregated_v4_chapters_{$id}_all");
        if (is_array($all) && ! empty($all)) {
            return $all;
        }

        $merged = [];
        foreach (['id', 'en'] as $language) {
            $cached = Cache::get("aggregated_v4_chapters_{$id}_{$language}");
            if (is_array($cached) && ! empty($cached)) {
                $merged = array_merge($merged, $cached);
            }
        }

        if (empty($merged)) {
            return [];
        }

        usort($merged, function ($a, $b) {
            $numA = (float) ($a['chapter'] ?? 0);
            $numB = (float) ($b['chapter'] ?? 0);
            if ($numA == $numB) {
                return strcmp($a['language'] ?? '', $b['language'] ?? '');
            }

            return ($numA < $numB) ? 1 : -1;
        });

        return array_values($merged);
    }

    /**
     * Build the compact JSON payload consumed by the detail page.
     */
    protected function buildChapterPayload(array $chapters, string $mangaId): array
    {
        $total = count($chapters);
        $indoCount = 0;
        $enCount = 0;
        foreach ($chapters as $chapter) {
            if (($chapter['language'] ?? '') === 'id') {
                $indoCount++;
            } elseif (($chapter['language'] ?? '') === 'en') {
                $enCount++;
            }
        }

        // Keep the payload small: the 400 newest chapters are enough for the
        // UI, while "first chapter" is still resolved from the full list.
        $visible = $total > 400 ? array_slice($chapters, 0, 400) : $chapters;

        return [
            'mangaId' => $mangaId,
            'chapters' => array_values(array_map([$this, 'chapterSummary'], $visible)),
            'counts' => [
                'all' => $total,
                'id' => $indoCount,
                'en' => $enCount,
            ],
            'first' => $total > 0 ? $this->chapterSummary(end($chapters)) : null,
            'latest' => $total > 0 ? $this->chapterSummary(reset($chapters)) : null,
            'truncated' => $total > count($visible),
        ];
    }

    /**
     * Only the fields the client needs to render / navigate a chapter.
     */
    protected function chapterSummary(array $chapter): array
    {
        return [
            'id' => (string) ($chapter['id'] ?? ''),
            'chapter' => (string) ($chapter['chapter'] ?? '0'),
            'title' => (string) ($chapter['title'] ?? ''),
            'language' => (string) ($chapter['language'] ?? 'en'),
            'provider' => (string) ($chapter['provider'] ?? 'MangaDex'),
            'publishAt' => (string) ($chapter['publishAt'] ?? ''),
            'externalUrl' => $chapter['externalUrl'] ?? null,
        ];
    }

    /**
     * Manga chapter reader interface (vertical & single-page modes).
     */
    public function read(string $id, string $chapterId, Request $request): Response
    {
        $source = $request->query('source');
        $chNum = $request->query('ch');
        $p = $request->query('p');

        // Fast-path: immediately prime URL cache if base64 readPath parameter is present
        if ($p) {
            $decodedPath = base64_decode($p, true);
            if ($decodedPath && filter_var($decodedPath, FILTER_VALIDATE_URL)) {
                $prefix = explode('_', $chapterId)[0] ?? '';
                $cacheKeyMap = [
                    'kmd' => "kmd_url_{$chapterId}",
                    'bk' => "bk_url_{$chapterId}",
                    'wm' => "wm_url_{$chapterId}",
                    'shn' => "shn_url_{$chapterId}",
                    'mh' => "mh_url_{$chapterId}",
                ];
                if (isset($cacheKeyMap[$prefix])) {
                    Cache::put($cacheKeyMap[$prefix], $decodedPath, 604800);
                }
            }
        }

        // 0. Ensure URL cache exists for non-MangaDex chapters (prevent 404 from expired URL cache)
        $this->ensureChapterUrlCache($id, $chapterId, $source);

        // 1. Fetch image pages ONLY from the specific provider (fast, direct, single-provider)
        $chapterData = $this->mangaService->getChapterPages($chapterId, $source);
        if (! $chapterData || empty($chapterData['pages'])) {
            abort(404, 'Halaman chapter tidak dapat dimuat atau chapter ini dialihkan ke web eksternal.');
        }

        // 2. Fetch manga metadata quickly from cache without re-aggregating
        $manga = Cache::get("manga_detail_{$id}");
        if (! $manga) {
            $manga = $this->mangaService->getDetail($id) ?? [
                'id' => $id,
                'title' => 'Manga Reader',
                'coverUrl' => '',
            ];
            Cache::put("manga_detail_{$id}", $manga, 7200);
        }

        // 3. Current chapter metadata (refined below from the cached chapter list)
        $currentChapter = $this->buildCurrentChapter($chapterId, $chNum, $source);

        // 4. Resolve prev/next within the same language from the cached chapter list
        $navigation = $this->resolveChapterNavigation($id, $chapterId, $chNum, $currentChapter);

        return response()
            ->view('manga.read', [
                'manga' => $manga,
                'currentChapter' => $navigation['currentChapter'],
                'prevChapter' => $navigation['prevChapter'],
                'nextChapter' => $navigation['nextChapter'],
                'allChapters' => $navigation['chapters'],
                'sameLangChapters' => $navigation['sameLangChapters'],
                'pages' => $chapterData['pages'],
                'totalPages' => count($chapterData['pages']),
            ])
            ->header('Cache-Control', 'public, max-age=120, s-maxage=3600, stale-while-revalidate=86400');
    }

    /**
     * Fast JSON API endpoint for background prefetching and zero-delay chapter transitions.
     */
    public function chapterPagesJson(string $id, string $chapterId, Request $request): \Illuminate\Http\JsonResponse
    {
        $source = $request->query('source');
        $chNum = $request->query('ch');
        $p = $request->query('p');

        if ($p) {
            $decodedPath = base64_decode($p, true);
            if ($decodedPath && filter_var($decodedPath, FILTER_VALIDATE_URL)) {
                $prefix = explode('_', $chapterId)[0] ?? '';
                $cacheKeyMap = [
                    'kmd' => "kmd_url_{$chapterId}",
                    'bk' => "bk_url_{$chapterId}",
                    'wm' => "wm_url_{$chapterId}",
                    'shn' => "shn_url_{$chapterId}",
                    'mh' => "mh_url_{$chapterId}",
                ];
                if (isset($cacheKeyMap[$prefix])) {
                    Cache::put($cacheKeyMap[$prefix], $decodedPath, 604800);
                }
            }
        }

        // Ensure URL cache exists for non-MangaDex chapters
        $this->ensureChapterUrlCache($id, $chapterId, $source);

        $chapterData = $this->mangaService->getChapterPages($chapterId, $source);
        if (! $chapterData || empty($chapterData['pages'])) {
            return response()->json(['error' => 'Chapter pages not found'], 404);
        }

        $currentChapter = $this->buildCurrentChapter($chapterId, $chNum, $source);
        $navigation = $this->resolveChapterNavigation($id, $chapterId, $chNum, $currentChapter);

        return response()
            ->json([
                'currentChapter' => $navigation['currentChapter'],
                'prevChapter' => $navigation['prevChapter'],
                'nextChapter' => $navigation['nextChapter'],
                'pages' => $chapterData['pages'],
                'totalPages' => count($chapterData['pages']),
            ])
            ->header('Cache-Control', 'public, max-age=300, s-maxage=7200, stale-while-revalidate=86400');
    }

    /**
     * Build a provisional chapter record from the request data.
     * It is replaced by the cached record when one can be matched.
     */
    protected function buildCurrentChapter(string $chapterId, ?string $chNum, ?string $source): array
    {
        return [
            'id' => $chapterId,
            'chapter' => $chNum ?: '1',
            'title' => '',
            'language' => preg_match('/^(kmk_|kmd_|wm_|bk_|shn_)/', $chapterId) ? 'id' : 'en',
            'provider' => $source ?: 'MangaDex',
        ];
    }

    /**
     * Load the aggregated chapter list from cache without triggering slow blocking aggregation.
     */
    protected function cachedChapters(string $mangaId, string $language): array
    {
        $keys = [
            "aggregated_v4_chapters_{$mangaId}_{$language}",
            "aggregated_v4_chapters_{$mangaId}_all",
            "aggregated_v4_chapters_{$mangaId}_id",
            "aggregated_v4_chapters_{$mangaId}_en",
            "aggregated_v4_chapters_{$mangaId}_null",
            "aggregated_v4_chapters_{$mangaId}_",
        ];

        foreach ($keys as $key) {
            $cached = Cache::get($key);
            if (! empty($cached)) {
                return $cached;
            }
        }

        // Return empty array rather than blocking the reader by calling getChapters synchronously!
        return [];
    }

    /**
     * Locate the current chapter and its same-language neighbours.
     *
     * @return array{currentChapter: array, prevChapter: ?array, nextChapter: ?array, sameLangChapters: array, chapters: array}
     */
    protected function resolveChapterNavigation(string $mangaId, string $chapterId, ?string $chNum, array $currentChapter): array
    {
        $chapters = $this->cachedChapters($mangaId, $currentChapter['language'] ?? 'en');

        if (empty($chapters)) {
            return [
                'currentChapter' => $currentChapter,
                'prevChapter' => null,
                'nextChapter' => null,
                'sameLangChapters' => [],
                'chapters' => [],
            ];
        }

        // Prefer an exact ID match, then fall back to the chapter number.
        foreach ($chapters as $chapter) {
            if (($chapter['id'] ?? null) === $chapterId) {
                $currentChapter = $chapter;
                break;
            }
        }

        if (($currentChapter['id'] ?? null) !== $chapterId && $chNum !== null && $chNum !== '') {
            $targetNum = (float) $chNum;
            foreach ($chapters as $chapter) {
                if ((float) ($chapter['chapter'] ?? -999) === $targetNum
                    && ($chapter['language'] ?? '') === ($currentChapter['language'] ?? 'en')) {
                    $currentChapter = $chapter;
                    break;
                }
            }
        }

        $currentLang = $currentChapter['language'] ?? 'en';
        $sameLangChapters = array_values(array_filter(
            $chapters,
            fn ($chapter) => ($chapter['language'] ?? 'id') === $currentLang
        ));

        if (empty($sameLangChapters)) {
            $sameLangChapters = $chapters;
        }

        $foundIndex = null;
        foreach ($sameLangChapters as $index => $chapter) {
            if (($chapter['id'] ?? null) === ($currentChapter['id'] ?? null)) {
                $foundIndex = $index;
                break;
            }
        }

        if ($foundIndex === null && $chNum !== null && $chNum !== '') {
            $targetNum = (float) $chNum;
            foreach ($sameLangChapters as $index => $chapter) {
                if ((float) ($chapter['chapter'] ?? -999) === $targetNum) {
                    $foundIndex = $index;
                    break;
                }
            }
        }

        $prevChapter = null;
        $nextChapter = null;

        if ($foundIndex !== null) {
            // Chapters are sorted descending (Ch. 232, 231, ... 1):
            // forward = higher number = lower index; backward = higher index.
            $nextChapter = $foundIndex > 0 ? $sameLangChapters[$foundIndex - 1] : null;
            $prevChapter = $sameLangChapters[$foundIndex + 1] ?? null;
        }

        return [
            'currentChapter' => $currentChapter,
            'prevChapter' => $prevChapter,
            'nextChapter' => $nextChapter,
            'sameLangChapters' => $sameLangChapters,
            'chapters' => $chapters,
        ];
    }

    /**
     * Ensure URL cache exists for non-MangaDex chapters.
     * Prevents 404 errors when the individual URL cache has expired
     * but the aggregated chapters cache still contains the readPath.
     */
    protected function ensureChapterUrlCache(string $mangaId, string $chapterId, ?string $source): void
    {
        $prefix = explode('_', $chapterId)[0] ?? '';
        $cacheKeyMap = [
            'kmd' => "kmd_url_{$chapterId}",
            'bk' => "bk_url_{$chapterId}",
            'wm' => "wm_url_{$chapterId}",
            'shn' => "shn_url_{$chapterId}",
            'mh' => "mh_url_{$chapterId}",
        ];

        if (! isset($cacheKeyMap[$prefix])) {
            return; // MangaDex or Komiku (kmk_) — no URL cache needed
        }

        $cacheKey = $cacheKeyMap[$prefix];

        // Already cached? Skip
        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return;
        }

        // 1. Look up readPath from the aggregated chapters cache
        $searchKeys = [
            "aggregated_v4_chapters_{$mangaId}_id",
            "aggregated_v4_chapters_{$mangaId}_all",
            "aggregated_v4_chapters_{$mangaId}_en",
        ];

        foreach ($searchKeys as $searchKey) {
            $chapters = \Illuminate\Support\Facades\Cache::get($searchKey, []);
            foreach ($chapters as $chap) {
                if ($chap['id'] === $chapterId && ! empty($chap['readPath'])) {
                    \Illuminate\Support\Facades\Cache::put($cacheKey, $chap['readPath'], 604800);

                    return;
                }
            }
        }

        // 2. Targeted single-provider recovery to eliminate 404 in <1s without freezing reader
        try {
            $manga = \Illuminate\Support\Facades\Cache::get("manga_detail_{$mangaId}")
                ?? $this->mangaService->getDetail($mangaId);
            $title = $manga['title'] ?? '';
            $altTitles = $manga['altTitles'] ?? [];

            if (in_array($prefix, ['bk', 'wm', 'shn'])) {
                $indo = app(\App\Services\Manga\Providers\MultiSourceProvider::class)->getIndoChapters($title, $altTitles);
                foreach ($indo as $chap) {
                    if ($chap['id'] === $chapterId && ! empty($chap['readPath'])) {
                        \Illuminate\Support\Facades\Cache::put($cacheKey, $chap['readPath'], 604800);

                        return;
                    }
                }
            } elseif ($prefix === 'kmd') {
                $indo = app(\App\Services\Manga\Providers\KomikIndoProvider::class)->getChapters($title, $altTitles);
                foreach ($indo as $chap) {
                    if ($chap['id'] === $chapterId && ! empty($chap['readPath'])) {
                        \Illuminate\Support\Facades\Cache::put($cacheKey, $chap['readPath'], 604800);

                        return;
                    }
                }
            } elseif ($prefix === 'wb') {
                $en = app(\App\Services\Manga\Providers\MultiSourceProvider::class)->getEnglishChapters($title, $altTitles);
                foreach ($en as $chap) {
                    if ($chap['id'] === $chapterId && ! empty($chap['readPath'])) {
                        \Illuminate\Support\Facades\Cache::put($cacheKey, $chap['readPath'], 604800);

                        return;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Proxy image requests to bypass ISP censorship and edge restrictions.
     */
    public function proxyImage(Request $request): BinaryFileResponse|Response|\Illuminate\Http\RedirectResponse
    {
        $url = $request->query('url');
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            abort(400, 'Invalid image URL');
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            abort(404, 'Invalid host');
        }

        // Validate SSRF against private/loopback/reserved IPs (matches MangaTest security expectations)
        $ip = gethostbyname($host);
        if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            abort(404, 'Host cannot be resolved');
        }
        $ipToCheck = filter_var($host, FILTER_VALIDATE_IP) ? $host : $ip;
        if (! filter_var($ipToCheck, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            abort(404, 'Access denied to private IP');
        }

        // If requested as a fallback because external CDN failed, fetch directly through our Referer-preserving proxy
        if ($request->boolean('fallback')) {
            $data = $this->mangaService->fetchImage($url);
            if ($data && ! empty($data['content'])) {
                return response($data['content'])
                    ->header('Content-Type', $data['contentType'] ?? 'image/jpeg')
                    ->header('Cache-Control', 'public, max-age=604800, s-maxage=604800, stale-while-revalidate=86400, immutable');
            }
        }

        return redirect('https://wsrv.nl/?url=' . urlencode($url) . '&n=-1', 301);
    }
}
