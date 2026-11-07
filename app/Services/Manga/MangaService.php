<?php

namespace App\Services\Manga;

use App\Services\Manga\Contracts\MangaProviderInterface;
use App\Services\Manga\Providers\MangaDexProvider;
use App\Services\Manga\Providers\KomikuProvider;
use App\Services\Manga\Providers\KomikIndoProvider;
use App\Services\Manga\Providers\MangaPillProvider;
use App\Services\Manga\Providers\MangaNatoProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MangaService
{
    protected MangaDexProvider $mangaDexProvider;
    protected KomikuProvider $komikuProvider;
    protected KomikIndoProvider $komikIndoProvider;
    protected MangaPillProvider $mangaPillProvider;
    protected MangaNatoProvider $mangaNatoProvider;

    public function __construct(
        ?MangaDexProvider $mangaDex = null,
        ?KomikuProvider $komiku = null,
        ?KomikIndoProvider $komikIndo = null,
        ?MangaPillProvider $mangaPill = null,
        ?MangaNatoProvider $mangaNato = null
    ) {
        $this->mangaDexProvider = $mangaDex ?? new MangaDexProvider();
        $this->komikuProvider = $komiku ?? new KomikuProvider();
        $this->komikIndoProvider = $komikIndo ?? new KomikIndoProvider();
        $this->mangaPillProvider = $mangaPill ?? new MangaPillProvider();
        $this->mangaNatoProvider = $mangaNato ?? new MangaNatoProvider();
    }

    /**
     * Get popular manga with fallback.
     */
    public function getPopular(int $page = 1, int $limit = 24, ?string $category = 'popular', ?string $genre = null): array
    {
        try {
            $result = $this->mangaDexProvider->getPopular($page, $limit, $category, $genre);
            if (!empty($result['data'])) {
                return $result;
            }
        } catch (\Throwable $e) {
            Log::error("MangaService getPopular error: " . $e->getMessage());
        }

        return $this->getFallbackCatalog($page, $limit);
    }

    /**
     * Search manga with query and optional category/genre filter.
     */
    public function search(string $query, int $page = 1, int $limit = 24, ?string $category = null, ?string $genre = null): array
    {
        try {
            return $this->mangaDexProvider->search($query, $page, $limit, $category, $genre);
        } catch (\Throwable $e) {
            Log::error("MangaService search error: " . $e->getMessage());
            return [
                'data' => [],
                'total' => 0,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => 1,
            ];
        }
    }

    /**
     * Get manga detail.
     */
    public function getDetail(string $id): ?array
    {
        try {
            $manga = $this->mangaDexProvider->getDetail($id);
            if ($manga && !empty($manga['title'])) {
                return $manga;
            }
        } catch (\Throwable $e) {
            Log::error("MangaService getDetail error: " . $e->getMessage());
        }

        // Fallback detail from catalog to prevent 404
        foreach ($this->getFallbackCatalog(1, 50)['data'] as $item) {
            if ($item['id'] === $id) {
                return [
                    'id' => $item['id'],
                    'title' => $item['title'],
                    'altTitles' => [$item['title']],
                    'description' => $item['description'],
                    'coverUrl' => $item['coverUrl'],
                    'status' => $item['status'],
                    'year' => $item['year'],
                    'contentRating' => $item['contentRating'],
                    'tags' => $item['tags'],
                    'authors' => 'Official Creator',
                    'artists' => 'Official Creator',
                    'hasIndo' => true,
                    'hasEnglish' => true,
                    'externalLinks' => [],
                ];
            }
        }

        return null;
    }

    /**
     * Multi-Source Hybrid Aggregator:
     * Combines 3 English sources (MangaDex, MangaPill, MangaNato) and
     * 3 Indonesian sources (Komiku, KomikIndo, MangaDex ID).
     * If one source has missing or DMCA'd chapters, other sources complement each other.
     */
    public function getChapters(string $id, ?string $language = null): array
    {
        $cacheKey = "aggregated_v2_chapters_{$id}_{$language}";

        return Cache::remember($cacheKey, 1800, function () use ($id, $language) {
            $manga = $this->getDetail($id);
            $title = $manga['title'] ?? '';
            $altTitles = $manga['altTitles'] ?? [];

            // 1. Primary Source: MangaDex Chapters (EN & ID)
            $dexChapters = [];
            try {
                $dexChapters = $this->mangaDexProvider->getChapters($id, $language);
                foreach ($dexChapters as &$dc) {
                    $dc['provider'] = 'MangaDex';
                }
            } catch (\Throwable $e) {
                Log::warning("MangaDex chapter fetch failed for {$id}: " . $e->getMessage());
            }

            // 2. Indonesian Sources (Komiku + KomikIndo)
            $indoChapters = [];
            if (!$language || $language === 'id') {
                // Indonesian Source 1: Komiku
                try {
                    $komiku = $this->komikuProvider->getChapters($title, $altTitles);
                    $indoChapters = array_merge($indoChapters, $komiku);
                } catch (\Throwable $e) {
                    Log::warning("Komiku chapter fetch failed: " . $e->getMessage());
                }

                // Indonesian Source 2: KomikIndo
                try {
                    if (count($indoChapters) < 10) {
                        $komikindo = $this->komikIndoProvider->getChapters($title, $altTitles);
                        $indoChapters = array_merge($indoChapters, $komikindo);
                    }
                } catch (\Throwable $e) {
                    Log::warning("KomikIndo chapter fetch failed: " . $e->getMessage());
                }
            }

            // 3. English Sources (MangaPill + MangaNato)
            $enChapters = [];
            if (!$language || $language === 'en') {
                // English Source 1: MangaPill (comprehensive English archive)
                try {
                    $mp = $this->mangaPillProvider->getChapters($title, $altTitles);
                    $enChapters = array_merge($enChapters, $mp);
                } catch (\Throwable $e) {
                    Log::warning("MangaPill chapter fetch failed: " . $e->getMessage());
                }

                // English Source 2: MangaNato (fallback)
                try {
                    if (count($enChapters) < 10) {
                        $nato = $this->mangaNatoProvider->getChapters($title, $altTitles);
                        $enChapters = array_merge($enChapters, $nato);
                    }
                } catch (\Throwable $e) {
                    Log::warning("MangaNato chapter fetch failed: " . $e->getMessage());
                }
            }

            // 4. Merge all sources together and deduplicate
            $all = array_merge($dexChapters, $indoChapters, $enChapters);

            // Deduplicate by language + chapter number
            $uniqueMap = [];
            foreach ($all as $chap) {
                $lang = $chap['language'] ?? 'en';
                $num = (string) ($chap['chapter'] ?? '0');
                $key = "{$lang}_{$num}";

                if (!isset($uniqueMap[$key])) {
                    $uniqueMap[$key] = $chap;
                } else {
                    // If current item is from MangaDex or Komiku, it has high-res images
                    if (empty($uniqueMap[$key]['title']) && !empty($chap['title'])) {
                        $uniqueMap[$key]['title'] = $chap['title'];
                    }
                }
            }

            $merged = array_values($uniqueMap);

            // Filter by requested language if specified
            if ($language && in_array($language, ['id', 'en'])) {
                $merged = array_values(array_filter($merged, fn($c) => ($c['language'] ?? '') === $language));
            }

            // Natural sort descending by chapter number
            usort($merged, function ($a, $b) {
                $numA = (float) ($a['chapter'] ?? 0);
                $numB = (float) ($b['chapter'] ?? 0);
                if ($numA == $numB) {
                    return strcmp($a['language'] ?? '', $b['language'] ?? '');
                }
                return ($numA < $numB) ? 1 : -1;
            });

            return $merged;
        });
    }

    /**
     * Get chapter pages dynamically based on provider prefix.
     */
    public function getChapterPages(string $chapterId): ?array
    {
        try {
            if (str_starts_with($chapterId, 'kmk_')) {
                return $this->komikuProvider->getChapterPages($chapterId);
            }
            if (str_starts_with($chapterId, 'mp_')) {
                return $this->mangaPillProvider->getChapterPages($chapterId);
            }
            if (str_starts_with($chapterId, 'nato_')) {
                return $this->mangaNatoProvider->getChapterPages($chapterId);
            }
            if (str_starts_with($chapterId, 'kmd_')) {
                return $this->komikIndoProvider->getChapterPages($chapterId);
            }

            return $this->mangaDexProvider->getChapterPages($chapterId);
        } catch (\Throwable $e) {
            Log::error("MangaService getChapterPages error for {$chapterId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Proxy fetch image with multi-domain DoH support.
     */
    public function fetchImage(string $url): ?array
    {
        return $this->mangaDexProvider->fetchImage($url);
    }

    /**
     * List of popular genres for quick filtering.
     */
    public function getGenres(): array
    {
        return [
            'Action',
            'Adventure',
            'Comedy',
            'Drama',
            'Fantasy',
            'Horror',
            'Isekai',
            'Mystery',
            'Psychological',
            'Romance',
            'Sci-Fi',
            'Slice of Life',
            'Supernatural',
            'Thriller',
        ];
    }

    /**
     * Fallback mock catalog in case external API is temporarily unreachable.
     */
    protected function getFallbackCatalog(int $page, int $limit): array
    {
        $mock = [
            [
                'id' => 'a77742b1-befd-49a4-bff5-1ad4e6b0ef7b',
                'title' => 'Chainsaw Man',
                'description' => 'Denji memiliki mimpi sederhana—hidup bahagia dan damai bersama gadis yang disukainya. Namun kenyataan pahit memaksanya bekerja sebagai pemburu iblis bersama iblis anjing berwujud gergaji bernama Pochita.',
                'coverUrl' => 'https://s4.anilist.co/file/anilistcdn/media/manga/cover/large/bx105778-9p15b49lF3o4.jpg',
                'status' => 'Ongoing',
                'year' => 2018,
                'contentRating' => 'Safe',
                'tags' => ['Action', 'Comedy', 'Supernatural'],
                'hasIndo' => true,
                'hasEnglish' => true,
            ],
            [
                'id' => '32d76d19-8a05-4db0-9fc2-e0b0648fe9d0',
                'title' => 'Solo Leveling (Na Honjaman Level-Up)',
                'description' => 'Di dunia tempat para hunter dengan kekuatan magis melawan monster mematikan, Sung Jinwoo adalah hunter terlemah dari yang terlemah, hingga ia mendapatkan akses quest misterius yang memungkinkan dirinya meningkatkan level tanpa batas.',
                'coverUrl' => 'https://s4.anilist.co/file/anilistcdn/media/manga/cover/large/bx105398-b6wtupMFjSTP.jpg',
                'status' => 'Completed',
                'year' => 2018,
                'contentRating' => 'Safe',
                'tags' => ['Action', 'Fantasy', 'Adventure'],
                'hasIndo' => true,
                'hasEnglish' => true,
            ],
            [
                'id' => 'e78a489b-6632-4d61-b069-52341f236239',
                'title' => 'One Piece',
                'description' => 'Gol D. Roger, Raja Bajak Laut, mengungkapkan keberadaan harta karun terbesarnya yang disebut "One Piece". Monkey D. Luffy memulai petualangannya untuk mengarungi Grand Line dan menjadi Raja Bajak Laut berikutnya.',
                'coverUrl' => 'https://s4.anilist.co/file/anilistcdn/media/manga/cover/large/bx30013-1mRhL48Yqf6k.jpg',
                'status' => 'Ongoing',
                'year' => 1997,
                'contentRating' => 'Safe',
                'tags' => ['Action', 'Adventure', 'Comedy', 'Fantasy'],
                'hasIndo' => true,
                'hasEnglish' => true,
            ],
            [
                'id' => 'c52b2ce3-7f95-469c-96b0-479524fb7a1a',
                'title' => 'Jujutsu Kaisen',
                'description' => 'Yuuji Itadori adalah siswa SMA dengan kekuatan fisik luar biasa yang secara tidak sengaja menelan jari terkutuk Ryomen Sukuna dan terjun ke dunia penyihir Jujutsu.',
                'coverUrl' => 'https://s4.anilist.co/file/anilistcdn/media/manga/cover/large/bx101517-57eJ8q8eS04s.jpg',
                'status' => 'Completed',
                'year' => 2018,
                'contentRating' => 'Safe',
                'tags' => ['Action', 'Supernatural', 'Fantasy'],
                'hasIndo' => true,
                'hasEnglish' => true,
            ],
            [
                'id' => 'a96676e5-8ae2-425e-b549-7f15dd34a6d8',
                'title' => 'Komi-san wa Komyushou Desu.',
                'description' => 'Komi Shouko adalah siswi paling anggun dan populer di sekolahnya, tetapi ia menderita kecemasan sosial ekstrem yang membuatnya sulit berkomunikasi.',
                'coverUrl' => 'https://s4.anilist.co/file/anilistcdn/media/manga/cover/large/bx86913-Yk0B8yCq9T0k.jpg',
                'status' => 'Ongoing',
                'year' => 2016,
                'contentRating' => 'Safe',
                'tags' => ['Comedy', 'Romance', 'Slice of Life'],
                'hasIndo' => true,
                'hasEnglish' => true,
            ],
            [
                'id' => 'b76735be-5ce2-4752-b883-93cf0e81e9f2',
                'title' => 'Sousou no Frieren',
                'description' => 'Penyihir elf Frieren mengalahkan Raja Iblis bersama kelompok pahlawannya. Sebagai makhluk abadi, ia memulai perjalanan baru untuk memahami arti hidup manusia dan perasaan yang ditinggalkan rekan-rekannya.',
                'coverUrl' => 'https://s4.anilist.co/file/anilistcdn/media/manga/cover/large/bx118556-h7Z4q6zYn9F2.jpg',
                'status' => 'Ongoing',
                'year' => 2020,
                'contentRating' => 'Safe',
                'tags' => ['Adventure', 'Drama', 'Fantasy'],
                'hasIndo' => true,
                'hasEnglish' => true,
            ],
            [
                'id' => '6b1eb93e-473a-4ab3-9922-1a66d2a29a4a',
                'title' => 'SPY x FAMILY',
                'description' => 'Untuk menyelesaikan misi rahasia menyelamatkan perdamaian dunia, mata-mata nomor satu Twilight harus membentuk keluarga palsu dengan menikahi seorang pembunuh bayaran dan mengadopsi anak telepatis.',
                'coverUrl' => 'https://s4.anilist.co/file/anilistcdn/media/manga/cover/large/bx108556-9Z2T1YFf2G0w.jpg',
                'status' => 'Ongoing',
                'year' => 2019,
                'contentRating' => 'Safe',
                'tags' => ['Action', 'Comedy', 'Slice of Life'],
                'hasIndo' => true,
                'hasEnglish' => true,
            ],
            [
                'id' => '304ceac3-8cdb-4fe7-acf7-2b6ff7a60613',
                'title' => 'Tokyo Ghoul',
                'description' => 'Ken Kaneki berubah menjadi setengah ghoul setelah kencan mematikan, memaksanya menjalani kehidupan rahasia di antara manusia dan monster pemakan daging.',
                'coverUrl' => 'https://s4.anilist.co/file/anilistcdn/media/manga/cover/large/bx63327-0N2v9Yk4y2x1.jpg',
                'status' => 'Completed',
                'year' => 2011,
                'contentRating' => 'Safe',
                'tags' => ['Action', 'Horror', 'Psychological'],
                'hasIndo' => true,
                'hasEnglish' => true,
            ],
        ];

        return [
            'data' => $mock,
            'total' => count($mock),
            'page' => $page,
            'limit' => $limit,
            'totalPages' => 1,
        ];
    }
}
