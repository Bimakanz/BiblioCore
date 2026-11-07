<?php

namespace App\Services\Manga;

use App\Services\Manga\Contracts\MangaProviderInterface;
use App\Services\Manga\Providers\AniListProvider;
use App\Services\Manga\Providers\MangaDexProvider;
use App\Services\Manga\Providers\KomikuProvider;
use App\Services\Manga\Providers\KomikIndoProvider;
use App\Services\Manga\Providers\MangaPillProvider;
use App\Services\Manga\Providers\MangaNatoProvider;
use App\Services\Manga\Providers\MultiSourceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MangaService
{
    protected AniListProvider $aniListProvider;
    protected MangaDexProvider $mangaDexProvider;
    protected KomikuProvider $komikuProvider;
    protected KomikIndoProvider $komikIndoProvider;
    protected MangaPillProvider $mangaPillProvider;
    protected MangaNatoProvider $mangaNatoProvider;
    protected MultiSourceProvider $multiSourceProvider;

    public function __construct(
        ?AniListProvider $aniList = null,
        ?MangaDexProvider $mangaDex = null,
        ?KomikuProvider $komiku = null,
        ?KomikIndoProvider $komikIndo = null,
        ?MangaPillProvider $mangaPill = null,
        ?MangaNatoProvider $mangaNato = null,
        ?MultiSourceProvider $multiSource = null
    ) {
        $this->aniListProvider = $aniList ?? new AniListProvider();
        $this->mangaDexProvider = $mangaDex ?? new MangaDexProvider();
        $this->komikuProvider = $komiku ?? new KomikuProvider();
        $this->komikIndoProvider = $komikIndo ?? new KomikIndoProvider();
        $this->mangaPillProvider = $mangaPill ?? new MangaPillProvider();
        $this->mangaNatoProvider = $mangaNato ?? new MangaNatoProvider();
        $this->multiSourceProvider = $multiSource ?? new MultiSourceProvider();
    }

    /**
     * Get popular manga with fallback.
     */
    public function getPopular(int $page = 1, int $limit = 24, ?string $category = 'popular', ?string $genre = null): array
    {
        // 1. Primary Global Catalog: AniList GraphQL (Fast, 100% reliable covers, never blocked by Cloudflare/datacenter IPs)
        try {
            $result = $this->aniListProvider->getPopular($page, $limit, $category, $genre);
            if (!empty($result['data'])) {
                return $result;
            }
        } catch (\Throwable $e) {
            Log::warning("AniList getPopular failed: " . $e->getMessage());
        }

        // 2. Secondary Catalog: MangaDex
        try {
            $result = $this->mangaDexProvider->getPopular($page, $limit, $category, $genre);
            if (!empty($result['data'])) {
                return $result;
            }
        } catch (\Throwable $e) {
            Log::warning("MangaDex getPopular failed: " . $e->getMessage());
        }

        return $this->getFallbackCatalog($page, $limit);
    }

    /**
     * Search manga with query and optional category/genre filter.
     */
    public function search(string $query, int $page = 1, int $limit = 24, ?string $category = null, ?string $genre = null): array
    {
        $result = null;
        try {
            $result = $this->aniListProvider->search($query, $page, $limit, $category, $genre);
        } catch (\Throwable $e) {
            Log::warning("AniList search failed: " . $e->getMessage());
        }

        if (empty($result['data'])) {
            try {
                $result = $this->mangaDexProvider->search($query, $page, $limit, $category, $genre);
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

        if (!empty($result['data'])) {
            // Deduplicate cards by normalized title AND format across sources
            // (If both Manga and Novel exist: keep 2 results; duplicate sources are merged into 1)
            $deduped = [];
            $seenKeys = [];
            foreach ($result['data'] as $item) {
                $normTitle = strtolower(preg_replace('/[^a-z0-9]/', '', $item['title'] ?? ''));
                $formatKey = strtolower($item['format'] ?? 'manga');
                $key = "{$normTitle}_{$formatKey}";

                if (!empty($normTitle) && !isset($seenKeys[$key])) {
                    $seenKeys[$key] = true;
                    $deduped[] = $item;
                } elseif (empty($normTitle)) {
                    $deduped[] = $item;
                }
            }
            $result['data'] = $deduped;
            return $result;
        }

        return [
            'data' => [],
            'total' => 0,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => 1,
        ];
    }

    /**
     * Get manga detail.
     */
    public function getDetail(string $id): ?array
    {
        $cacheKey = "manga_detail_{$id}";

        return Cache::remember($cacheKey, 86400, function () use ($id) {
            if (str_starts_with($id, 'al_') || is_numeric($id)) {
                try {
                    $manga = $this->aniListProvider->getDetail($id);
                    if ($manga) {
                        return $manga;
                    }
                } catch (\Throwable $e) {
                    Log::warning("AniList getDetail failed for {$id}: " . $e->getMessage());
                }
            }

            try {
                $manga = $this->mangaDexProvider->getDetail($id);
                if ($manga && !empty($manga['title'])) {
                    return $manga;
                }
            } catch (\Throwable $e) {
                Log::warning("MangaDex getDetail failed for {$id}: " . $e->getMessage());
            }

            // Fallback detail from catalog to prevent 404
            foreach ($this->getFallbackCatalog(1, 50)['data'] as $item) {
                if ($item['id'] === $id) {
                    return [
                        'id' => $item['id'],
                        'title' => $item['title'],
                        'format' => $item['format'] ?? 'MANGA',
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
        });
    }

    /**
     * Multi-Source Hybrid Aggregator:
     * Combines 3 English sources (MangaDex, MangaPill, MangaNato) and
     * 3 Indonesian sources (Komiku, KomikIndo, MangaDex ID).
     * If one source has missing or DMCA'd chapters, other sources complement each other.
     */
    public function getChapters(string $id, ?string $language = null, ?bool &$partial = null): array
    {
        $partial = false;
        $langKey = $language ?: 'all';
        $cacheKey = "aggregated_v4_chapters_{$id}_{$langKey}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ! empty($cached)) {
            return $cached;
        }

        // Hard time budget: we would rather return a partial list than let the
        // serverless function hit its timeout (which produces a 504 and no
        // cache at all). Provider-level caches make the next attempt much
        // cheaper, so partial results still converge quickly.
        $startedAt = microtime(true);
        $budget = (float) env('CHAPTERS_TIME_BUDGET', 15);
        $deadlineHit = false;
        $hasTime = function () use ($startedAt, $budget, &$deadlineHit): bool {
            if ((microtime(true) - $startedAt) >= $budget) {
                $deadlineHit = true;

                return false;
            }

            return true;
        };

        $chapters = (function () use ($id, $language, $hasTime, &$deadlineHit) {
            $manga = $this->getDetail($id);
            $title = $manga['title'] ?? '';
            $altTitles = $manga['altTitles'] ?? [];

            // 1. Primary Source: MangaDex Chapters (EN & ID)
            $dexChapters = [];
            if ($hasTime()) {
                try {
                    $dexId = $id;
                    if (str_starts_with($id, 'al_') || is_numeric($id)) {
                        $search = $this->mangaDexProvider->search($title, 1, 5);
                        if (! empty($search['data'])) {
                            $normalize = fn (string $s) => strtolower(trim(preg_replace('/[^a-z0-9]/', '', $s)));
                            $targetNorm = $normalize($title);
                            foreach ($search['data'] as $cand) {
                                $candNorm = $normalize($cand['title'] ?? '');
                                similar_text($targetNorm, $candNorm, $pct);
                                if ($targetNorm === $candNorm || $pct >= 65) {
                                    $dexId = $cand['id'];
                                    break;
                                }
                            }
                        }
                    }
                    if ($dexId && ! str_starts_with($dexId, 'al_')) {
                        $dexChapters = $this->mangaDexProvider->getChapters($dexId, $language);
                        foreach ($dexChapters as &$dc) {
                            $dc['provider'] = 'MangaDex';
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("MangaDex chapter fetch failed for {$id}: ".$e->getMessage());
                }
            }

            // Count what MangaDex already delivered. When it has a healthy
            // archive we skip the slower scrapers entirely: this is the single
            // biggest cold-start win and avoids hammering external sites.
            $dexIndoCount = count(array_filter($dexChapters, fn ($c) => ($c['language'] ?? '') === 'id'));
            $dexEnCount = count(array_filter($dexChapters, fn ($c) => ($c['language'] ?? '') === 'en'));
            $enoughChapters = 40;

            // 2. Indonesian Sources (Komiku + KomikIndo + WestManga + Bacakomik + Shinigami ID)
            $indoChapters = [];
            if ((! $language || $language === 'id') && $dexIndoCount < $enoughChapters && $hasTime()) {
                // Indonesian Source 1: Komiku
                try {
                    if ($hasTime() && count($indoChapters) + $dexIndoCount < $enoughChapters) {
                        $komiku = $this->komikuProvider->getChapters($title, $altTitles);
                        $indoChapters = array_merge($indoChapters, $komiku);
                    }
                } catch (\Throwable $e) {
                    Log::warning("Komiku chapter fetch failed: " . $e->getMessage());
                }

                // Indonesian Source 2: KomikIndo
                try {
                    if ($hasTime() && count($indoChapters) + $dexIndoCount < $enoughChapters) {
                        $komikindo = $this->komikIndoProvider->getChapters($title, $altTitles);
                        $indoChapters = array_merge($indoChapters, $komikindo);
                    }
                } catch (\Throwable $e) {
                    Log::warning("KomikIndo chapter fetch failed: " . $e->getMessage());
                }

                // Indonesian Source 3: MultiSource (Bacakomik, WestManga)
                try {
                    if ($hasTime() && count($indoChapters) + $dexIndoCount < $enoughChapters) {
                        $multiId = $this->multiSourceProvider->getIndoChapters($title, $altTitles);
                        $indoChapters = array_merge($indoChapters, $multiId);
                    }
                } catch (\Throwable $e) {
                    Log::warning("MultiSource Indo chapter fetch failed: " . $e->getMessage());
                }
            }

            // 3. English Sources (MangaPill + MangaNato + WeebCentral + MangaHere)
            $enChapters = [];
            if ((! $language || $language === 'en') && $dexEnCount < $enoughChapters && $hasTime()) {
                // English Source 1: MangaPill (comprehensive English archive)
                try {
                    if ($hasTime() && count($enChapters) + $dexEnCount < $enoughChapters) {
                        $mp = $this->mangaPillProvider->getChapters($title, $altTitles);
                        $enChapters = array_merge($enChapters, $mp);
                    }
                } catch (\Throwable $e) {
                    Log::warning("MangaPill chapter fetch failed: " . $e->getMessage());
                }

                // English Source 2: WeebCentral & MangaHere
                try {
                    if ($hasTime() && count($enChapters) + $dexEnCount < $enoughChapters) {
                        $multiEn = $this->multiSourceProvider->getEnglishChapters($title, $altTitles);
                        $enChapters = array_merge($enChapters, $multiEn);
                    }
                } catch (\Throwable $e) {
                    Log::warning("MultiSource EN chapter fetch failed: " . $e->getMessage());
                }

                // English Source 3: MangaNato (fallback)
                try {
                    if ($hasTime() && count($enChapters) + $dexEnCount < $enoughChapters) {
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

            // Natural sort descending by chapter number
            usort($merged, function ($a, $b) {
                $numA = (float) ($a['chapter'] ?? 0);
                $numB = (float) ($b['chapter'] ?? 0);
                if ($numA == $numB) {
                    return strcmp($a['language'] ?? '', $b['language'] ?? '');
                }
                return ($numA < $numB) ? 1 : -1;
            });

            $ttl = $deadlineHit ? 300 : 43200;

            // If fetching all languages, also prime individual language caches
            if (! $language) {
                $indoCh = array_values(array_filter($merged, fn ($c) => ($c['language'] ?? '') === 'id'));
                $enCh = array_values(array_filter($merged, fn ($c) => ($c['language'] ?? '') === 'en'));
                Cache::put("aggregated_v4_chapters_{$id}_id", $indoCh, $ttl);
                Cache::put("aggregated_v4_chapters_{$id}_en", $enCh, $ttl);
            }

            // Filter by requested language if specified
            if ($language && in_array($language, ['id', 'en'])) {
                $merged = array_values(array_filter($merged, fn ($c) => ($c['language'] ?? '') === $language));
            }

            return $merged;
        })();

        // Never keep an empty aggregation around: a transient upstream failure
        // should be retried on the next visit instead of sticking for 12 hours.
        if (empty($chapters)) {
            Cache::forget($cacheKey);
        } else {
            // Partial results expire quickly so the next request can finish the job.
            Cache::put($cacheKey, $chapters, $deadlineHit ? 300 : 43200);
        }

        $partial = $deadlineHit;

        return $chapters;
    }

    /**
     * Get chapter pages dynamically based on provider prefix or direct source.
     */
    public function getChapterPages(string $chapterId, ?string $source = null): ?array
    {
        try {
            if ($source === 'Komiku' || str_starts_with($chapterId, 'kmk_')) {
                return $this->komikuProvider->getChapterPages($chapterId);
            }
            if ($source === 'MangaPill' || str_starts_with($chapterId, 'mp_')) {
                return $this->mangaPillProvider->getChapterPages($chapterId);
            }
            if ($source === 'MangaNato' || str_starts_with($chapterId, 'nato_')) {
                return $this->mangaNatoProvider->getChapterPages($chapterId);
            }
            if ($source === 'KomikIndo' || str_starts_with($chapterId, 'kmd_')) {
                return $this->komikIndoProvider->getChapterPages($chapterId);
            }
            if (in_array($source, ['WeebCentral', 'MangaHere', 'WestManga', 'Bacakomik', 'Shinigami ID']) || preg_match('/^(wb_|mh_|wm_|bk_|shn_)/', $chapterId)) {
                return $this->multiSourceProvider->getChapterPages($chapterId);
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
