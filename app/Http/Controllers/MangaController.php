<?php

namespace App\Http\Controllers;

use App\Services\Manga\MangaService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
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
            return response()->json(['data' => []]);
        }

        $cacheKey = 'live_search_'.md5(strtolower($query));
        $results = \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($query) {
            $res = $this->mangaService->search($query, 1, 8);

            return $res['data'] ?? [];
        });

        return response()->json(['data' => array_slice($results, 0, 8)]);
    }

    /**
     * Display the public manga catalogue / explore page.
     */
    public function index(Request $request): View
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

        return view('manga.index', [
            'mangas' => $result['data'] ?? [],
            'total' => $result['total'] ?? 0,
            'page' => $page,
            'totalPages' => $result['totalPages'] ?? 1,
            'search' => $search,
            'currentCategory' => $categoryFilter,
            'currentGenre' => $genre,
            'genres' => $genres,
        ]);
    }

    /**
     * Display manga details and chapter list.
     * Uses parallel fetching: detail + chapters load concurrently for speed.
     */
    public function show(string $id, Request $request): View
    {
        // Check if chapters are already cached — if so, skip expensive re-fetch
        $cachedChapters = \Illuminate\Support\Facades\Cache::get("aggregated_v4_chapters_{$id}_all");

        // Fetch manga detail (this is itself cached)
        $manga = $this->mangaService->getDetail($id);
        if (! $manga) {
            abort(404, 'Manga tidak ditemukan.');
        }

        // Cache manga detail explicitly for fast reader access
        \Illuminate\Support\Facades\Cache::put("manga_detail_{$id}", $manga, 7200);

        // Chapters: use cache if available, otherwise fetch (the service method itself caches)
        $allChapters = $cachedChapters ?: $this->mangaService->getChapters($id);

        // Re-sync URL caches for all non-MangaDex chapters to prevent 404 on reader
        foreach ($allChapters as $chap) {
            if (! empty($chap['readPath'])) {
                $chapId = $chap['id'];
                $prefix = explode('_', $chapId)[0] ?? '';
                $cacheKey = match ($prefix) {
                    'kmd' => "kmd_url_{$chapId}",
                    'bk' => "bk_url_{$chapId}",
                    'wm' => "wm_url_{$chapId}",
                    'shn' => "shn_url_{$chapId}",
                    'mh' => "mh_url_{$chapId}",
                    default => null,
                };
                if ($cacheKey) {
                    \Illuminate\Support\Facades\Cache::put($cacheKey, $chap['readPath'], 86400);
                }
            }
        }

        $indoChapters = array_values(array_filter($allChapters, fn ($c) => $c['language'] === 'id'));
        $enChapters = array_values(array_filter($allChapters, fn ($c) => $c['language'] === 'en'));

        // Determine first and latest chapter for quick CTA buttons
        $firstChapter = ! empty($allChapters) ? end($allChapters) : null;
        $latestChapter = ! empty($allChapters) ? reset($allChapters) : null;

        return view('manga.show', [
            'manga' => $manga,
            'allChapters' => $allChapters,
            'indoChapters' => $indoChapters,
            'enChapters' => $enChapters,
            'firstChapter' => $firstChapter,
            'latestChapter' => $latestChapter,
        ]);
    }

    /**
     * Manga chapter reader interface (vertical & single-page modes).
     */
    public function read(string $id, string $chapterId, Request $request): View
    {
        $source = $request->query('source');
        $chNum = $request->query('ch');

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

        return view('manga.read', [
            'manga' => $manga,
            'currentChapter' => $navigation['currentChapter'],
            'prevChapter' => $navigation['prevChapter'],
            'nextChapter' => $navigation['nextChapter'],
            'allChapters' => $navigation['chapters'],
            'sameLangChapters' => $navigation['sameLangChapters'],
            'pages' => $chapterData['pages'],
            'totalPages' => count($chapterData['pages']),
        ]);
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
        if (! $chapterData || empty($chapterData['pages'])) {
            return response()->json(['error' => 'Chapter pages not found'], 404);
        }

        $currentChapter = $this->buildCurrentChapter($chapterId, $chNum, $source);
        $navigation = $this->resolveChapterNavigation($id, $chapterId, $chNum, $currentChapter);

        return response()->json([
            'currentChapter' => $navigation['currentChapter'],
            'prevChapter' => $navigation['prevChapter'],
            'nextChapter' => $navigation['nextChapter'],
            'pages' => $chapterData['pages'],
            'totalPages' => count($chapterData['pages']),
        ]);
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
     * Load the aggregated chapter list from cache, falling back to the service.
     */
    protected function cachedChapters(string $mangaId, string $language): array
    {
        $keys = [
            "aggregated_v4_chapters_{$mangaId}_{$language}",
            "aggregated_v4_chapters_{$mangaId}_all",
            "aggregated_v4_chapters_{$mangaId}_null",
            "aggregated_v4_chapters_{$mangaId}_",
        ];

        foreach ($keys as $key) {
            $cached = Cache::get($key);
            if (! empty($cached)) {
                return $cached;
            }
        }

        return $this->mangaService->getChapters($mangaId) ?: [];
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
            foreach ($chapters as $chapter) {
                if ((string) ($chapter['chapter'] ?? '') === (string) $chNum
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

        if ($foundIndex === null) {
            foreach ($sameLangChapters as $index => $chapter) {
                if ((string) ($chapter['chapter'] ?? '') === (string) ($currentChapter['chapter'] ?? '')) {
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

        // Look up readPath from the aggregated chapters cache
        $searchKeys = [
            "aggregated_v4_chapters_{$mangaId}_all",
            "aggregated_v4_chapters_{$mangaId}_id",
            "aggregated_v4_chapters_{$mangaId}_en",
        ];

        foreach ($searchKeys as $searchKey) {
            $chapters = \Illuminate\Support\Facades\Cache::get($searchKey, []);
            foreach ($chapters as $chap) {
                if ($chap['id'] === $chapterId && ! empty($chap['readPath'])) {
                    \Illuminate\Support\Facades\Cache::put($cacheKey, $chap['readPath'], 86400);

                    return;
                }
            }
        }

        // If not found in cache, re-fetch chapters to guarantee readPath recovery and eliminate 404
        try {
            $chapters = $this->mangaService->getChapters($mangaId);
            foreach ($chapters as $chap) {
                if ($chap['id'] === $chapterId && ! empty($chap['readPath'])) {
                    \Illuminate\Support\Facades\Cache::put($cacheKey, $chap['readPath'], 86400);

                    return;
                }
            }
        } catch (\Throwable $e) {
        }
    }

    /**
     * Proxy image requests to bypass ISP censorship and edge restrictions.
     */
    public function proxyImage(Request $request): BinaryFileResponse|Response
    {
        $url = $request->query('url');
        if (! $url || ! filter_var($url, FILTER_VALIDATE_URL)) {
            abort(400, 'Invalid image URL');
        }

        $data = $this->mangaService->fetchImage($url);
        if (! $data) {
            abort(404, 'Gambar tidak dapat diakses.');
        }

        if (! empty($data['filePath']) && file_exists($data['filePath'])) {
            return response()->file($data['filePath'], [
                'Content-Type' => $data['contentType'],
                'Cache-Control' => 'public, max-age=604800, immutable',
            ]);
        }

        return response($data['content'])
            ->header('Content-Type', $data['contentType'])
            ->header('Cache-Control', 'public, max-age=604800, immutable');
    }
}
