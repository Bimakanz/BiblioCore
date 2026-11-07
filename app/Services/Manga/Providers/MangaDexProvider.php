<?php

namespace App\Services\Manga\Providers;

use App\Services\Manga\Contracts\MangaProviderInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class MangaDexProvider implements MangaProviderInterface
{
    protected string $apiBase = 'https://api.mangadex.org';
    protected array $dnsResolves = [
        'api.mangadex.org:443:45.129.229.2',
        'uploads.mangadex.org:443:45.129.229.2',
        'mangadex.org:443:45.129.229.2',
        'img.komiku.org:443:104.21.40.71',
        'komiku.org:443:104.21.40.71',
        'mangapill.com:443:104.21.5.54',
        'cdn.readdetectiveconan.com:443:104.21.5.54',
        'readmanganato.com:443:162.4.84.255',
    ];

    protected array $tagMap = [
        'Action' => '391b0423-d847-456f-aff0-8b0cfc03066b',
        'Adventure' => '87cc87cd-a395-47af-b27a-93258283bbc6',
        'Comedy' => '4d32cc48-9f00-4cca-9b5a-a839f0764984',
        'Drama' => 'b9af3a63-f058-46de-a9a0-e0c13906197a',
        'Fantasy' => 'cdc58593-87dd-415e-bbc0-2ec27bf404cc',
        'Horror' => 'cdad7e68-1419-41dd-bdce-27753074a640',
        'Isekai' => 'ace04997-f6bd-436e-b261-779182193d3d',
        'Mystery' => 'ee968100-4191-4968-93d3-f82d72be7e46',
        'Romance' => '423e2eae-a7a2-4a8b-ac03-a8351462d71d',
        'Sci-Fi' => '256c8bd9-4904-4360-bf4f-508a76d67183',
        'Slice of Life' => 'e5301a23-ebd9-49dd-a0cb-2add944c7fe9',
        'Supernatural' => 'e197df38-d0e7-43b5-9b09-2842d0c326dd',
        'Thriller' => '070d70fe-38e1-4b10-a1fb-023555248b37',
    ];

    /**
     * Helper to perform cURL requests to MangaDex with DoH DNS resolve fallback.
     */
    protected function request(string $endpoint, array $params = [], float|int $timeout = 2.5): ?array
    {
        $url = $this->apiBase . $endpoint;
        if (!empty($params)) {
            $query = http_build_query($params);
            // Replace numeric array indices like arr%5B0%5D with arr%5B%5D
            $query = preg_replace('/%5B[0-9]+%5D/', '%5B%5D', $query);
            $url .= (str_contains($url, '?') ? '&' : '?') . $query;
        }

        $execute = function (bool $useResolve) use ($url, $timeout): ?array {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            if ($useResolve && !empty($this->dnsResolves)) {
                curl_setopt($ch, CURLOPT_RESOLVE, $this->dnsResolves);
            }
            curl_setopt($ch, CURLOPT_USERAGENT, 'BiblioManga/1.0.0 (https://github.com/Bimakanz/BiblioCore; contact@bibliomanga.app)');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response !== false && $httpCode >= 200 && $httpCode < 400) {
                return json_decode($response, true);
            }

            return null;
        };

        $isVercel = isset($_ENV['VERCEL']) || isset($_SERVER['VERCEL']) || env('VERCEL');

        // On Vercel / serverless: Vercel US datacenter has direct unpoisoned DNS. Try standard DNS first!
        if ($isVercel) {
            $data = $execute(false);
            if ($data !== null) {
                return $data;
            }
            return $execute(true);
        }

        // On local dev (e.g. Indonesia with ISP DNS block): try resolve first, fallback to standard
        $data = $execute(true);
        if ($data !== null) {
            return $data;
        }
        return $execute(false);
    }

    /**
     * Fetch high-resolution, unblocked manga covers in batch via AniList GraphQL.
     */
    public function getAniListCovers(array $alIds): array
    {
        $alIds = array_values(array_filter(array_unique(array_map('intval', $alIds))));
        if (empty($alIds)) {
            return [];
        }

        $cacheKey = 'al_covers_batch_' . md5(implode(',', $alIds));
        return Cache::remember($cacheKey, 86400, function () use ($alIds) {
            $query = '
            query ($ids: [Int]) {
              Page (page: 1, perPage: 50) {
                media (id_in: $ids, type: MANGA) {
                  id
                  coverImage { extraLarge large medium }
                }
              }
            }';

            $ch = curl_init('https://graphql.anilist.co');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query, 'variables' => ['ids' => $alIds]]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
            curl_setopt($ch, CURLOPT_USERAGENT, 'BiblioManga-App/1.0');

            $raw = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($raw, true);
            $map = [];
            foreach ($data['data']['Page']['media'] ?? [] as $m) {
                $cover = $m['coverImage']['extraLarge'] ?? $m['coverImage']['large'] ?? $m['coverImage']['medium'] ?? null;
                if ($cover) {
                    $map[(int)$m['id']] = $cover;
                }
            }

            return $map;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getPopular(int $page = 1, int $limit = 24, ?string $category = 'popular', ?string $genre = null): array
    {
        $offset = ($page - 1) * $limit;
        $category = in_array($category, ['popular', 'trending', 'ongoing', 'completed']) ? $category : 'popular';
        $cacheKey = "mangadex_category_{$category}_{$page}_{$limit}_{$genre}";

        return Cache::remember($cacheKey, 1800, function () use ($offset, $limit, $category, $genre, $page) {
            $params = [
                'limit' => $limit,
                'offset' => $offset,
                'includes' => ['cover_art'],
                'contentRating' => ['safe'],
            ];

            if ($category === 'trending') {
                $params['order'] = ['rating' => 'desc'];
            } elseif ($category === 'ongoing') {
                $params['status'] = ['ongoing'];
                $params['order'] = ['latestUploadedChapter' => 'desc'];
            } elseif ($category === 'completed') {
                $params['status'] = ['completed'];
                $params['order'] = ['followedCount' => 'desc'];
            } else {
                // Default: popular
                $params['order'] = ['followedCount' => 'desc'];
            }

            if ($genre && !empty($this->tagMap[$genre])) {
                $params['includedTags'] = [$this->tagMap[$genre]];
            }

            $raw = $this->request('/manga', $params);
            if (!$raw || empty($raw['data'])) {
                return [
                    'data' => [],
                    'total' => 0,
                    'page' => $page,
                    'limit' => $limit,
                    'totalPages' => 1,
                ];
            }

            // Extract AniList IDs for fast, 100% reliable cover images
            $alIds = [];
            foreach ($raw['data'] as $item) {
                if (!empty($item['attributes']['links']['al'])) {
                    $alIds[] = (int) $item['attributes']['links']['al'];
                }
            }
            $alCovers = $this->getAniListCovers($alIds);

            $list = array_map(fn($item) => $this->formatMangaCard($item, $alCovers), $raw['data']);
            $total = $raw['total'] ?? count($list);

            return [
                'data' => $list,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => ceil($total / $limit),
            ];
        });
    }

    /**
     * {@inheritdoc}
     */
    public function search(string $query, int $page = 1, int $limit = 24, ?string $category = null, ?string $genre = null): array
    {
        $offset = ($page - 1) * $limit;
        $cacheKey = "mangadex_search_" . md5("{$query}_{$page}_{$limit}_{$category}_{$genre}");

        return Cache::remember($cacheKey, 900, function () use ($query, $offset, $limit, $category, $genre, $page) {
            $params = [
                'limit' => $limit,
                'offset' => $offset,
                'includes' => ['cover_art'],
                'order' => ['relevance' => 'desc'],
                'contentRating' => ['safe'],
            ];

            if (trim($query) !== '') {
                $params['title'] = trim($query);
            }

            if ($category === 'ongoing') {
                $params['status'] = ['ongoing'];
            } elseif ($category === 'completed') {
                $params['status'] = ['completed'];
            }

            if ($genre && !empty($this->tagMap[$genre])) {
                $params['includedTags'] = [$this->tagMap[$genre]];
            }

            $raw = $this->request('/manga', $params);
            if (!$raw || empty($raw['data'])) {
                return [
                    'data' => [],
                    'total' => 0,
                    'page' => $page,
                    'limit' => $limit,
                    'totalPages' => 1,
                ];
            }

            $alIds = [];
            foreach ($raw['data'] as $item) {
                if (!empty($item['attributes']['links']['al'])) {
                    $alIds[] = (int) $item['attributes']['links']['al'];
                }
            }
            $alCovers = $this->getAniListCovers($alIds);

            $list = array_map(fn($item) => $this->formatMangaCard($item, $alCovers), $raw['data']);
            $total = $raw['total'] ?? count($list);

            return [
                'data' => $list,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => ceil($total / $limit),
            ];
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getDetail(string $id): ?array
    {
        $cacheKey = "mangadex_detail_{$id}";

        return Cache::remember($cacheKey, 3600, function () use ($id) {
            $raw = $this->request("/manga/{$id}", [
                'includes' => ['cover_art', 'author', 'artist'],
            ]);

            if (!$raw || empty($raw['data'])) {
                return null;
            }

            $manga = $raw['data'];
            $alCovers = [];
            if (!empty($manga['attributes']['links']['al'])) {
                $alCovers = $this->getAniListCovers([(int)$manga['attributes']['links']['al']]);
            }

            $card = $this->formatMangaCard($manga, $alCovers);

            // Fetch authors / artists if present in relationships
            $authors = [];
            $artists = [];
            foreach ($manga['relationships'] ?? [] as $rel) {
                if ($rel['type'] === 'author' && !empty($rel['attributes']['name'])) {
                    $authors[] = $rel['attributes']['name'];
                }
                if ($rel['type'] === 'artist' && !empty($rel['attributes']['name'])) {
                    $artists[] = $rel['attributes']['name'];
                }
            }

            $card['authors'] = !empty($authors) ? implode(', ', $authors) : 'Unknown';
            $card['artists'] = !empty($artists) ? implode(', ', $artists) : $card['authors'];
            $card['altTitles'] = array_map(fn($t) => reset($t), $manga['attributes']['altTitles'] ?? []);

            return $card;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getChapters(string $id, ?string $language = null, int $limit = 200, int $offset = 0): array
    {
        $cacheKey = "mangadex_chapters_{$id}_{$language}_{$limit}_{$offset}";

        return Cache::remember($cacheKey, 1800, function () use ($id, $language, $limit, $offset) {
            $params = [
                'limit' => $limit,
                'offset' => $offset,
                'order' => ['chapter' => 'desc'],
                'includes' => ['scanlation_group'],
            ];

            if ($language && in_array($language, ['id', 'en'])) {
                $params['translatedLanguage'] = [$language];
            } else {
                $params['translatedLanguage'] = ['id', 'en'];
            }

            $raw = $this->request("/manga/{$id}/feed", $params);
            if (!$raw || empty($raw['data'])) {
                return [];
            }

            $chapters = [];
            foreach ($raw['data'] as $item) {
                $attr = $item['attributes'] ?? [];
                
                // Scanlation group
                $group = 'Official / Fan Scan';
                foreach ($item['relationships'] ?? [] as $rel) {
                    if ($rel['type'] === 'scanlation_group' && !empty($rel['attributes']['name'])) {
                        $group = $rel['attributes']['name'];
                        break;
                    }
                }

                $chapNumber = $attr['chapter'] ?? 'Oneshot';
                $title = $attr['title'] ?? '';
                $lang = $attr['translatedLanguage'] ?? 'en';
                $publishAt = $attr['publishAt'] ?? $attr['createdAt'] ?? null;
                $pages = (int) ($attr['pages'] ?? 0);
                $externalUrl = $attr['externalUrl'] ?? null;

                $chapters[] = [
                    'id' => $item['id'],
                    'chapter' => $chapNumber,
                    'volume' => $attr['volume'] ?? null,
                    'title' => $title,
                    'language' => $lang,
                    'group' => $group,
                    'pages' => $pages,
                    'externalUrl' => $externalUrl,
                    'publishAt' => $publishAt ? date('d M Y', strtotime($publishAt)) : '-',
                ];
            }

            // Natural sort by chapter descending
            usort($chapters, function ($a, $b) {
                $cA = is_numeric($a['chapter']) ? (float)$a['chapter'] : 0;
                $cB = is_numeric($b['chapter']) ? (float)$b['chapter'] : 0;
                return $cB <=> $cA;
            });

            return $chapters;
        });
    }

    /**
     * {@inheritdoc}
     */
    public function getChapterPages(string $chapterId): ?array
    {
        $cacheKey = "mangadex_pages_{$chapterId}";

        return Cache::remember($cacheKey, 600, function () use ($chapterId) {
            $raw = $this->request("/at-home/server/{$chapterId}");
            if (!$raw || empty($raw['baseUrl']) || empty($raw['chapter']['hash'])) {
                return null;
            }

            $baseUrl = rtrim($raw['baseUrl'], '/');
            $hash = $raw['chapter']['hash'];
            $files = $raw['chapter']['data'] ?? [];
            $filesSaver = $raw['chapter']['dataSaver'] ?? [];

            $pages = [];
            foreach ($files as $index => $file) {
                $directUrl = "{$baseUrl}/data/{$hash}/{$file}";
                $saverUrl = !empty($filesSaver[$index]) ? "{$baseUrl}/data-saver/{$hash}/{$filesSaver[$index]}" : $directUrl;

                // Relative proxy URLs to avoid port mismatch issues
                $proxyUrl = "/manga/proxy/image?url=" . urlencode($directUrl);
                $proxySaverUrl = "/manga/proxy/image?url=" . urlencode($saverUrl);

                $pages[] = [
                    'page' => $index + 1,
                    'directUrl' => $directUrl,
                    'proxyUrl' => $proxyUrl,
                    'saverUrl' => $saverUrl,
                    'proxySaverUrl' => $proxySaverUrl,
                ];
            }

            return [
                'chapterId' => $chapterId,
                'count' => count($pages),
                'pages' => $pages,
            ];
        });
    }

    /**
     * {@inheritdoc}
     */
    public function fetchImage(string $url): ?array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        // Cache on local disk
        $cacheDir = storage_path('app/manga_cache');
        if (!File::isDirectory($cacheDir)) {
            File::makeDirectory($cacheDir, 0755, true);
        }

        $hashKey = md5($url);
        $cachedFile = "{$cacheDir}/{$hashKey}";
        $metaFile = "{$cacheDir}/{$hashKey}.meta";

        if (File::exists($cachedFile) && File::exists($metaFile)) {
            $mime = trim(File::get($metaFile));
            return [
                'content' => File::get($cachedFile),
                'contentType' => $mime ?: 'image/jpeg',
                'filePath' => $cachedFile,
            ];
        }

        $isVercel = isset($_ENV['VERCEL']) || isset($_SERVER['VERCEL']) || env('VERCEL');

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        if (!$isVercel && !empty($this->dnsResolves)) {
            curl_setopt($ch, CURLOPT_RESOLVE, $this->dnsResolves);
        }
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
        
        $referer = 'https://mangadex.org/';
        if (str_contains($url, 'komiku') || str_contains($host, 'komiku')) {
            $referer = 'https://komiku.org/';
        } elseif (str_contains($url, 'mangapill') || str_contains($url, 'readdetectiveconan') || str_contains($host, 'readdetectiveconan')) {
            $referer = 'https://mangapill.com/';
        } elseif (str_contains($url, 'manganato') || str_contains($host, 'manganato')) {
            $referer = 'https://readmanganato.com/';
        } elseif (str_contains($url, 'komikindo') || str_contains($host, 'komikindo')) {
            $referer = 'https://komikindo.tv/';
        }
        curl_setopt($ch, CURLOPT_REFERER, $referer);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
        ]);

        $content = curl_exec($ch);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($content === false || $httpCode >= 400 || strlen($content) < 500) {
            return null;
        }

        // Validate that response is actually an image and not HTML / bot challenge
        $headerSnippet = strtolower(substr($content, 0, 300));
        if (str_contains($headerSnippet, '<!doctype') || 
            str_contains($headerSnippet, '<html') || 
            str_contains($headerSnippet, '<?xml') ||
            str_contains($headerSnippet, 'browser verification')) {
            Log::warning("Proxy received non-image/HTML response for URL: {$url}");
            return null;
        }

        $mime = $contentType ?: 'image/jpeg';
        File::put($cachedFile, $content);
        File::put($metaFile, $mime);

        return [
            'content' => $content,
            'contentType' => $mime,
            'filePath' => $cachedFile,
        ];
    }

    /**
     * Fetch cover image by title from Kitsu API (cached for 24 hours).
     */
    public function getCoverByTitle(string $title): ?string
    {
        $cleanTitle = trim($title);
        if ($cleanTitle === '') {
            return null;
        }

        $cacheKey = 'cover_by_title_' . md5($cleanTitle);
        return Cache::remember($cacheKey, 86400, function () use ($cleanTitle) {
            try {
                $ch = curl_init('https://kitsu.io/api/edge/manga?filter%5Btext%5D=' . urlencode($cleanTitle) . '&page%5Blimit%5D=1');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 4);
                curl_setopt($ch, CURLOPT_USERAGENT, 'BiblioManga/1.0');
                $res = curl_exec($ch);
                curl_close($ch);
                if ($res) {
                    $json = json_decode($res, true);
                    return $json['data'][0]['attributes']['posterImage']['large']
                        ?? $json['data'][0]['attributes']['posterImage']['medium']
                        ?? null;
                }
            } catch (\Throwable $e) {
                Log::debug("Kitsu cover lookup failed for {$cleanTitle}: " . $e->getMessage());
            }

            return null;
        });
    }

    /**
     * Standardize MangaDex manga object into a clean dictionary with AniList cover enhancement.
     */
    protected function formatMangaCard(array $manga, array $alCovers = []): array
    {
        $id = $manga['id'];
        $attr = $manga['attributes'] ?? [];

        // Title resolution
        $title = $attr['title']['en'] 
            ?? $attr['title']['ja-ro'] 
            ?? (is_array($attr['title'] ?? null) ? reset($attr['title']) : 'Untitled');

        // Description resolution
        $desc = $attr['description']['id'] 
            ?? $attr['description']['en'] 
            ?? (is_array($attr['description'] ?? null) ? reset($attr['description']) : 'Tidak ada sinopsis.');

        $desc = strip_tags(preg_replace('/\[(.*?)\]\(.*?\)/', '$1', (string) $desc));

        // 1. First priority: AniList high-resolution unblocked cover image
        $coverUrl = null;
        $alId = !empty($attr['links']['al']) ? (int) $attr['links']['al'] : null;
        if ($alId && !empty($alCovers[$alId])) {
            $coverUrl = $alCovers[$alId];
        }

        // 2. Second priority: Kitsu CDN poster by title
        if (!$coverUrl && !empty($title)) {
            $coverUrl = $this->getCoverByTitle($title);
        }

        // 3. Third priority: MangaDex cover via relative proxy
        if (!$coverUrl) {
            $coverFileName = null;
            foreach ($manga['relationships'] ?? [] as $rel) {
                if ($rel['type'] === 'cover_art' && !empty($rel['attributes']['fileName'])) {
                    $coverFileName = $rel['attributes']['fileName'];
                    break;
                }
            }

            if ($coverFileName) {
                $directCover = "https://uploads.mangadex.org/covers/{$id}/{$coverFileName}.512.jpg";
                $coverUrl = "/manga/proxy/image?url=" . urlencode($directCover);
            }
        }

        // Tags / Genres
        $tags = [];
        foreach ($attr['tags'] ?? [] as $tag) {
            $name = $tag['attributes']['name']['en'] ?? reset($tag['attributes']['name']);
            if ($name) {
                $tags[] = $name;
            }
        }

        // Available languages
        $availLangs = $attr['availableTranslatedLanguages'] ?? [];
        $hasIndo = in_array('id', $availLangs);
        $hasEnglish = in_array('en', $availLangs);

        return [
            'id' => $id,
            'title' => $title,
            'description' => $desc,
            'coverUrl' => $coverUrl,
            'status' => ucfirst($attr['status'] ?? 'Ongoing'),
            'year' => $attr['year'] ?? null,
            'contentRating' => ucfirst($attr['contentRating'] ?? 'Safe'),
            'tags' => array_slice($tags, 0, 5),
            'hasIndo' => $hasIndo,
            'hasEnglish' => $hasEnglish,
            'availableLanguages' => $availLangs,
        ];
    }
}
