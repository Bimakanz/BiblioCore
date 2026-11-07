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
     */
    public function show(string $id, Request $request): View
    {
        $manga = $this->mangaService->getDetail($id);
        if (!$manga) {
            abort(404, 'Manga tidak ditemukan.');
        }

        // Cache manga detail explicitly for fast reader access
        \Illuminate\Support\Facades\Cache::put("manga_detail_{$id}", $manga, 7200);

        $allChapters = $this->mangaService->getChapters($id);

        $indoChapters = array_values(array_filter($allChapters, fn($c) => $c['language'] === 'id'));
        $enChapters = array_values(array_filter($allChapters, fn($c) => $c['language'] === 'en'));

        // Determine first and latest chapter for quick CTA buttons
        $firstChapter = !empty($allChapters) ? end($allChapters) : null;
        $latestChapter = !empty($allChapters) ? reset($allChapters) : null;

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

        // 4. Retrieve cached chapters for prev/next without running scrapers
        $currentLang = $currentChapter['language'];
        $cachedChapters = \Illuminate\Support\Facades\Cache::get("aggregated_v4_chapters_{$id}_{$currentLang}")
            ?: \Illuminate\Support\Facades\Cache::get("aggregated_v4_chapters_{$id}_all")
            ?: \Illuminate\Support\Facades\Cache::get("aggregated_v4_chapters_{$id}_null")
            ?: \Illuminate\Support\Facades\Cache::get("aggregated_v4_chapters_{$id}_")
            ?: [];

        $prevChapter = null;
        $nextChapter = null;
        $sameLangChapters = [];

        if (!empty($cachedChapters)) {
            $sameLangChapters = array_values(array_filter($cachedChapters, fn($c) => ($c['language'] ?? 'id') === $currentLang));
            foreach ($sameLangChapters as $index => $chap) {
                if ($chap['id'] === $chapterId || (string)($chap['chapter'] ?? '') === (string)$chNum) {
                    $currentChapter = $chap;
                    $nextChapter = $sameLangChapters[$index - 1] ?? null;
                    $prevChapter = $sameLangChapters[$index + 1] ?? null;
                    break;
                }
            }
        }

        return view('manga.read', [
            'manga' => $manga,
            'currentChapter' => $currentChapter,
            'prevChapter' => $prevChapter,
            'nextChapter' => $nextChapter,
            'allChapters' => $cachedChapters,
            'sameLangChapters' => $sameLangChapters,
            'pages' => $chapterData['pages'],
            'totalPages' => count($chapterData['pages']),
        ]);
    }

    /**
     * Proxy image requests to bypass ISP censorship and edge restrictions.
     */
    public function proxyImage(Request $request): BinaryFileResponse|Response
    {
        $url = $request->query('url');
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            abort(400, 'Invalid image URL');
        }

        $data = $this->mangaService->fetchImage($url);
        if (!$data) {
            abort(404, 'Gambar tidak dapat diakses.');
        }

        if (!empty($data['filePath']) && file_exists($data['filePath'])) {
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
