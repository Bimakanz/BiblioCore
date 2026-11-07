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
            return $this->mangaDexProvider->getDetail($id);
        } catch (\Throwable $e) {
            Log::error("MangaService getDetail error: " . $e->getMessage());
            return null;
        }
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
                'description' => 'Denji memiliki mimpi sederhana—hidup bahagia dan damai, menghabiskan waktu bersama gadis yang disukainya. Namun kenyataan pahit memaksanya bekerja sebagai pemburu iblis.',
                'coverUrl' => null,
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
                'description' => 'Di dunia tempat para hunter dengan kekuatan magis melawan monster mematikan, Sung Jinwoo adalah hunter terlemah dari yang terlemah.',
                'coverUrl' => null,
                'status' => 'Completed',
                'year' => 2018,
                'contentRating' => 'Safe',
                'tags' => ['Action', 'Fantasy', 'Adventure'],
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
