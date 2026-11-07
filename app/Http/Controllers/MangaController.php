<?php

namespace App\Http\Controllers;

use App\Services\Manga\MangaService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

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
            return response()->json(['data' => []]);
        }

        $cacheKey = 'live_search_' . md5(strtolower($query));
        $results = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($query) {
            $res = $this->mangaService->search($query, 1, 8);
            return $res['data'] ?? [];
        });

        return response()->json(['data' => array_slice($results, 0, 8)])
            ->header('Cache-Control', 'public, s-maxage=3600, stale-while-revalidate=86400');
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

        return response()->view('manga.index', [
            'mangas' => $result['data'] ?? [],
            'total' => $result['total'] ?? 0,
            'page' => $page,
            'totalPages' => $result['totalPages'] ?? 1,
            'search' => $search,
            'currentCategory' => $categoryFilter,
            'currentGenre' => $genre,
            'genres' => $genres,
        ])->header('Cache-Control', 'public, s-maxage=1800, stale-while-revalidate=86400');
    }

    /**
     * Display manga details and chapter list.
     * Uses parallel fetching: detail + chapters load concurrently for speed.
     */
    public function show(string $id, Request $request): Response
    {
        // Check if chapters are already cached — if so, skip expensive re-fetch
        $cachedChapters = \Illuminate\Support\Facades\Cache::get("aggregated_v4_chapters_{$id}_all");

        // Fetch manga detail (this is itself cached)
        $manga = $this->mangaService->getDetail($id);
        if (!$manga) {
            abort(404, 'Manga tidak ditemukan.');
        }

        // Cache manga detail explicitly for fast reader access
        \Illuminate\Support\Facades\Cache::put("manga_detail_{$id}", $manga, 7200);

        // Chapters: use cache if available, otherwise fetch (the service method itself caches)
        $allChapters = $cachedChapters ?: $this->mangaService->getChapters($id);

        // Re-sync URL caches for all non-MangaDex chapters to prevent 404 on reader
        foreach ($allChapters as $chap) {
            if (!empty($chap['readPath'])) {
                $chapId = $chap['id'];
                $prefix = explode('_', $chapId)[0] ?? '';
                $cacheKey = match($prefix) {
                    'kmd' => "kmd_url_{$chapId}",
                    'bk'  => "bk_url_{$chapId}",
                    'wm'  => "wm_url_{$chapId}",
                    'shn' => "shn_url_{$chapId}",
                    'mh'  => "mh_url_{$chapId}",
                    default => null,
                };
                if ($cacheKey) {
                    \Illuminate\Support\Facades\Cache::put($cacheKey, $chap['readPath'], 86400);
                }
            }
        }

        $indoChapters = array_values(array_filter($allChapters, fn($c) => $c['language'] === 'id'));
        $enChapters = array_values(array_filter($allChapters, fn($c) => $c['language'] === 'en'));

        // Determine first and latest chapter for quick CTA buttons
        $firstChapter = !empty($allChapters) ? end($allChapters) : null;
        $latestChapter = !empty($allChapters) ? reset($allChapters) : null;

        return response()->view('manga.show', [
            'manga' => $manga,
            'allChapters' => $allChapters,
            'indoChapters' => $indoChapters,
            'enChapters' => $enChapters,
            'firstChapter' => $firstChapter,
            'latestChapter' => $latestChapter,
        ])->header('Cache-Control', 'public, s-maxage=3600, stale-while-revalidate=86400');
    }

    /**
     * Manga chapter reader interface (vertical & single-page modes).
     */
    public function read(string $id, string $chapterId, Request $request): Response
    {
        $source = $request->query('source');
        $chNum = $request->query('ch');

        // 0. Ensure URL cache exists for non-MangaDex chapters (prevent 404 from expired URL cache)
        $this->ensureChapterUrlCache($id, $chapterId, $source);

        // 1. Fetch image pages ONLY from the specific provider (fast, direct, single-provider)
        $chapterData = $this->mangaService->getChapterPages($chapterId, $source);
        if (!$chapterData || empty($chapterData['pages'])) {
            abort(404, 'Halaman chapter tidak dapat dimuat atau chapter ini dialihkan ke web eksternal.');
        }

        // 2. Fetch manga metadata quickly from cache without re-aggregating
        $manga = \Illuminate\Support\Facades\Cache::get("manga_detail_{$id}");
        if (!$manga) {
            $manga = $this->mangaService->getDetail($id) ?? [
                'id' => $id,
                'title' => 'Manga Reader',
                'coverUrl' => '',
            ];
            \Illuminate\Support\Facades\Cache::put("manga_detail_{$id}", $manga, 7200);
        }

        // 3. Current chapter metadata
        $currentChapter = [
            'id' => $chapterId,
            'chapter' => $chNum ?: '1',
            'title' => '',
            'language' => str_starts_with($chapterId, 'kmk_') || str_starts_with($chapterId, 'kmd_') || str_starts_with($chapterId, 'wm_') || str_starts_with($chapterId, 'bk_') || str_starts_with($chapterId, 'shn_') ? 'id' : 'en',
            'provider' => $source ?: 'MangaDex',
        ];

        // 4. Retrieve cached chapters for prev/next (fallback to getChapters if cache is empty)
        $currentLang = $currentChapter['language'];
        $cachedChapters = \Illuminate\Support\Facades\Cache::get("aggregated_v4_chapters_{$id}_{$currentLang}")
            ?: \Illuminate\Support\Facades\Cache::get("aggregated_v4_chapters_{$id}_all")
            ?: \Illuminate\Support\Facades\Cache::get("aggregated_v4_chapters_{$id}_null")
            ?: \Illuminate\Support\Facades\Cache::get("aggregated_v4_chapters_{$id}_")
            ?: [];

        if (empty($cachedChapters)) {
            $cachedChapters = $this->mangaService->getChapters($id);
        }

        if (empty($cachedChapters)) {
            $cachedChapters = $this->mangaService->getChapters($id);
        }

        $prevChapter = null;
        $nextChapter = null;
        $sameLangChapters = [];

        if (!empty($cachedChapters)) {
            // First locate current chapter — prioritize exact ID match
            $foundById = false;
            foreach ($cachedChapters as $chap) {
                if ($chap['id'] === $chapterId) {
                    $currentChapter = $chap;
                    $currentLang = $chap['language'] ?? $currentLang;
                    $foundById = true;
                    break;
                }
            }
            // Fallback: match by chapter number if ID not found
            if (!$foundById && !empty($chNum)) {
                foreach ($cachedChapters as $chap) {
                    if ((string)($chap['chapter'] ?? '') === (string)$chNum && ($chap['language'] ?? '') === $currentLang) {
                        $currentChapter = $chap;
                        break;
                    }
                }
            }

            // Filter strictly by the same language to prevent cross-language jumps
            $sameLangChapters = array_values(array_filter($cachedChapters, fn($c) => ($c['language'] ?? 'id') === $currentLang));
            if (empty($sameLangChapters)) {
                $sameLangChapters = $cachedChapters;
            }

            // Find prev/next by exact ID match within the same-language sorted list
            $foundIndex = null;
            foreach ($sameLangChapters as $index => $chap) {
                if ($chap['id'] === $currentChapter['id']) {
                    $foundIndex = $index;
                    break;
                }
            }
            // Fallback: match by chapter number
            if ($foundIndex === null) {
                foreach ($sameLangChapters as $index => $chap) {
                    if ((string)($chap['chapter'] ?? '') === (string)$currentChapter['chapter']) {
                        $foundIndex = $index;
                        break;
                    }
                }
            }

            if ($foundIndex !== null) {
                // Chapters are sorted descending (e.g. Ch. 232, 231, 230, ..., 1):
                // Next chapter to read (forward) has higher chapter number = lower index (index - 1)
                $nextChapter = ($foundIndex > 0) ? $sameLangChapters[$foundIndex - 1] : null;
                // Previous chapter (backward) has lower chapter number = higher index (index + 1)
                $prevChapter = $sameLangChapters[$foundIndex + 1] ?? null;
            }
        }

        return response()->view('manga.read', [
            'manga' => $manga,
            'currentChapter' => $currentChapter,
            'prevChapter' => $prevChapter,
            'nextChapter' => $nextChapter,
            'allChapters' => $cachedChapters,
            'sameLangChapters' => $sameLangChapters,
            'pages' => $chapterData['pages'],
            'totalPages' => count($chapterData['pages']),
        ])->header('Cache-Control', 'public, s-maxage=3600, stale-while-revalidate=86400');
    }

    /**
     * Fast JSON API endpoint for background prefetching and zero-delay chapter transitions.
     */
    public function chapterPagesJson(string $id, string $chapterId, Request $request): \Illuminate\Http\JsonResponse
    {
        $source = $request->query('source');
        $chNum = $request->query('ch');

        // Ensure URL cache exists for non-MangaDex chapters
        $this->ensureChapterUrlCache($id, $chapterId, $source);

        $chapterData = $this->mangaService->getChapterPages($chapterId, $source);
        if (!$chapterData || empty($chapterData['pages'])) {
            return response()->json(['error' => 'Chapter pages not found'], 404);
        }

        $currentChapter = [
            'id' => $chapterId,
            'chapter' => $chNum ?: '1',
            'title' => '',
            'language' => str_starts_with($chapterId, 'kmk_') || str_starts_with($chapterId, 'kmd_') || str_starts_with($chapterId, 'wm_') || str_starts_with($chapterId, 'bk_') || str_starts_with($chapterId, 'shn_') ? 'id' : 'en',
            'provider' => $source ?: 'MangaDex',
        ];

        $currentLang = $currentChapter['language'];
        $cachedChapters = \Illuminate\Support\Facades\Cache::get("aggregated_v4_chapters_{$id}_{$currentLang}")
            ?: \Illuminate\Support\Facades\Cache::get("aggregated_v4_chapters_{$id}_all")
            ?: \Illuminate\Support\Facades\Cache::get("aggregated_v4_chapters_{$id}_null")
            ?: \Illuminate\Support\Facades\Cache::get("aggregated_v4_chapters_{$id}_")
            ?: [];

        if (empty($cachedChapters)) {
            $cachedChapters = $this->mangaService->getChapters($id);
        }

        $prevChapter = null;
        $nextChapter = null;
        if (!empty($cachedChapters)) {
            // Locate current chapter — prioritize exact ID match
            $foundById = false;
            foreach ($cachedChapters as $chap) {
                if ($chap['id'] === $chapterId) {
                    $currentChapter = $chap;
                    $currentLang = $chap['language'] ?? $currentLang;
                    $foundById = true;
                    break;
                }
            }
            if (!$foundById && !empty($chNum)) {
                foreach ($cachedChapters as $chap) {
                    if ((string)($chap['chapter'] ?? '') === (string)$chNum && ($chap['language'] ?? '') === $currentLang) {
                        $currentChapter = $chap;
                        break;
                    }
                }
            }

            $sameLangChapters = array_values(array_filter($cachedChapters, fn($c) => ($c['language'] ?? 'id') === $currentLang));
            if (empty($sameLangChapters)) $sameLangChapters = $cachedChapters;

            // Find position by exact ID first
            $foundIndex = null;
            foreach ($sameLangChapters as $index => $chap) {
                if ($chap['id'] === $currentChapter['id']) {
                    $foundIndex = $index;
                    break;
                }
            }
            if ($foundIndex === null) {
                foreach ($sameLangChapters as $index => $chap) {
                    if ((string)($chap['chapter'] ?? '') === (string)$currentChapter['chapter']) {
                        $foundIndex = $index;
                        break;
                    }
                }
            }

            if ($foundIndex !== null) {
                $nextChapter = ($foundIndex > 0) ? $sameLangChapters[$foundIndex - 1] : null;
                $prevChapter = $sameLangChapters[$foundIndex + 1] ?? null;
            }
        }

        return response()->json([
            'currentChapter' => $currentChapter,
            'prevChapter' => $prevChapter,
            'nextChapter' => $nextChapter,
            'pages' => $chapterData['pages'],
            'totalPages' => count($chapterData['pages']),
        ])->header('Cache-Control', 'public, s-maxage=3600, stale-while-revalidate=86400');
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
            'bk'  => "bk_url_{$chapterId}",
            'wm'  => "wm_url_{$chapterId}",
            'shn' => "shn_url_{$chapterId}",
            'mh'  => "mh_url_{$chapterId}",
        ];

        if (!isset($cacheKeyMap[$prefix])) {
            return; // MangaDex or Komiku (kmk_) — no URL cache needed
        }

        $cacheKey = $cacheKeyMap[$prefix];

        // Already cached? Skip
        if (\Illuminate\Support\Facades\Cache::has($cacheKey)) {
            return;
        }

        // Look up readPath from the aggregated chapters cache
        $searchKeys = [
            "aggregated_v4_chapters_{$mangaId}_all",
            "aggregated_v4_chapters_{$mangaId}_id",
            "aggregated_v4_chapters_{$mangaId}_en",
        ];

        foreach ($searchKeys as $searchKey) {
            $chapters = \Illuminate\Support\Facades\Cache::get($searchKey, []);
            foreach ($chapters as $chap) {
                if ($chap['id'] === $chapterId && !empty($chap['readPath'])) {
                    \Illuminate\Support\Facades\Cache::put($cacheKey, $chap['readPath'], 86400);
                    return;
                }
            }
        }

        // If not found in cache, re-fetch chapters to guarantee readPath recovery and eliminate 404
        try {
            $chapters = $this->mangaService->getChapters($mangaId);
            foreach ($chapters as $chap) {
                if ($chap['id'] === $chapterId && !empty($chap['readPath'])) {
                    \Illuminate\Support\Facades\Cache::put($cacheKey, $chap['readPath'], 86400);
                    return;
                }
            }
        } catch (\Throwable $e) {}
    }

    /**
     * Proxy image requests: offloaded to wsrv.nl CDN to eliminate Serverless Function overhead.
     */
    public function proxyImage(Request $request): \Illuminate\Http\RedirectResponse
    {
        $url = $request->query('url');
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            abort(400, 'Invalid image URL');
        }

        return redirect('https://wsrv.nl/?url=' . urlencode($url) . '&n=-1', 301)
            ->header('Cache-Control', 'public, max-age=604800, immutable');
    }
}
