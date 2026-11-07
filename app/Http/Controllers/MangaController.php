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
        $manga = $this->mangaService->getDetail($id);
        if (!$manga) {
            abort(404, 'Manga tidak ditemukan.');
        }

        $chapterData = $this->mangaService->getChapterPages($chapterId);
        if (!$chapterData || empty($chapterData['pages'])) {
            abort(404, 'Halaman chapter tidak dapat dimuat atau chapter ini dialihkan ke web eksternal.');
        }

        $allChapters = $this->mangaService->getChapters($id);

        // Find current chapter info
        $currentChapter = null;
        foreach ($allChapters as $chap) {
            if ($chap['id'] === $chapterId) {
                $currentChapter = $chap;
                break;
            }
        }

        if (!$currentChapter) {
            $currentChapter = [
                'id' => $chapterId,
                'chapter' => '1',
                'title' => '',
                'language' => str_starts_with($chapterId, 'kmk_') ? 'id' : 'en',
            ];
        }

        $currentLang = $currentChapter['language'] ?? 'id';

        // Filter chapters strictly by the same language to prevent cross-language jumps
        $sameLangChapters = array_values(array_filter($allChapters, fn($c) => ($c['language'] ?? 'id') === $currentLang));

        $prevChapter = null;
        $nextChapter = null;

        foreach ($sameLangChapters as $index => $chap) {
            if ($chap['id'] === $chapterId) {
                // Chapters are sorted descending (e.g. 500, 499, ..., 2, 1):
                // Next in reading order is the chapter with a higher number (index - 1)
                $nextChapter = $sameLangChapters[$index - 1] ?? null;
                // Prev in reading order is index + 1
                $prevChapter = $sameLangChapters[$index + 1] ?? null;
                break;
            }
        }

        return view('manga.read', [
            'manga' => $manga,
            'currentChapter' => $currentChapter,
            'prevChapter' => $prevChapter,
            'nextChapter' => $nextChapter,
            'allChapters' => $allChapters,
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
