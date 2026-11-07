<?php

namespace App\Services\Manga\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AniListProvider
{
    protected string $endpoint = 'https://graphql.anilist.co';

    /**
     * Perform GraphQL query to AniList.
     */
    protected function query(string $query, array $variables = []): ?array
    {
        $payload = json_encode([
            'query' => $query,
            'variables' => $variables,
        ]);

        $ch = curl_init($this->endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BiblioManga/1.0.0 (https://github.com/Bimakanz/BiblioCore)');

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($res === false || $httpCode >= 400) {
            Log::warning("AniList GraphQL request failed ($httpCode): $err");
            return null;
        }

        return json_decode($res, true);
    }

    /**
     * Get popular manga with sorting and filters.
     */
    public function getPopular(int $page = 1, int $limit = 24, ?string $category = 'popular', ?string $genre = null): array
    {
        $cacheKey = "anilist_catalog_{$category}_{$page}_{$limit}_" . md5((string)$genre);

        return Cache::remember($cacheKey, 1800, function () use ($page, $limit, $category, $genre) {
            $variables = [
                'page' => $page,
                'perPage' => $limit,
                'type' => 'MANGA',
            ];

            // Category sorting & status
            if ($category === 'trending') {
                $variables['sort'] = ['TRENDING_DESC'];
            } elseif ($category === 'ongoing') {
                $variables['status'] = 'RELEASING';
                $variables['sort'] = ['POPULARITY_DESC'];
            } elseif ($category === 'completed') {
                $variables['status'] = 'FINISHED';
                $variables['sort'] = ['POPULARITY_DESC'];
            } else {
                $variables['sort'] = ['POPULARITY_DESC'];
            }

            if ($genre) {
                $variables['genre'] = $genre;
            }

            $query = '
            query ($page: Int, $perPage: Int, $type: MediaType, $sort: [MediaSort], $status: MediaStatus, $genre: String) {
              Page(page: $page, perPage: $perPage) {
                pageInfo {
                  total
                  perPage
                  currentPage
                  lastPage
                  hasNextPage
                }
                media(type: $type, sort: $sort, status: $status, genre: $genre, isAdult: false) {
                  id
                  format
                  title {
                    romaji
                    english
                    native
                  }
                  synonyms
                  description
                  coverImage {
                    extraLarge
                    large
                    medium
                  }
                  bannerImage
                  status
                  startDate {
                    year
                  }
                  genres
                  staff(perPage: 3) {
                    nodes {
                      name {
                        full
                      }
                    }
                  }
                }
              }
            }';

            $response = $this->query($query, $variables);
            $pageData = $response['data']['Page'] ?? null;

            if (!$pageData || empty($pageData['media'])) {
                return [
                    'data' => [],
                    'total' => 0,
                    'page' => $page,
                    'limit' => $limit,
                    'totalPages' => 1,
                ];
            }

            $list = array_map([$this, 'formatMangaCard'], $pageData['media']);
            
            // Deduplicate by normalized title AND format (Manga vs Novel results preserved, duplicates merged)
            $deduped = [];
            $seenKeys = [];
            foreach ($list as $item) {
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
            $list = $deduped;

            $total = $pageData['pageInfo']['total'] ?? count($list);
            $totalPages = $pageData['pageInfo']['lastPage'] ?? ceil($total / $limit);

            return [
                'data' => $list,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => max(1, $totalPages),
            ];
        });
    }

    /**
     * Search manga by keyword.
     */
    public function search(string $query, int $page = 1, int $limit = 24, ?string $category = null, ?string $genre = null): array
    {
        $cacheKey = "anilist_search_" . md5("{$query}_{$page}_{$limit}_{$category}_{$genre}");

        return Cache::remember($cacheKey, 900, function () use ($query, $page, $limit, $genre) {
            $variables = [
                'page' => $page,
                'perPage' => $limit,
                'search' => $query,
                'type' => 'MANGA',
                'sort' => ['SEARCH_MATCH'],
            ];

            if ($genre) {
                $variables['genre'] = $genre;
            }

            $gql = '
            query ($page: Int, $perPage: Int, $search: String, $type: MediaType, $sort: [MediaSort], $genre: String) {
              Page(page: $page, perPage: $perPage) {
                pageInfo {
                  total
                  lastPage
                }
                media(type: $type, search: $search, sort: $sort, genre: $genre, isAdult: false) {
                  id
                  format
                  title {
                    romaji
                    english
                    native
                  }
                  synonyms
                  description
                  coverImage {
                    extraLarge
                    large
                    medium
                  }
                  status
                  startDate {
                    year
                  }
                  genres
                  staff(perPage: 3) {
                    nodes {
                      name {
                        full
                      }
                    }
                  }
                }
              }
            }';

            $response = $this->query($gql, $variables);
            $pageData = $response['data']['Page'] ?? null;

            if (!$pageData || empty($pageData['media'])) {
                return [
                    'data' => [],
                    'total' => 0,
                    'page' => $page,
                    'limit' => $limit,
                    'totalPages' => 1,
                ];
            }

            $list = array_map([$this, 'formatMangaCard'], $pageData['media']);
            
            // Deduplicate by normalized title AND format (Manga vs Novel results preserved, duplicates merged)
            $deduped = [];
            $seenKeys = [];
            foreach ($list as $item) {
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
            $list = $deduped;

            $total = $pageData['pageInfo']['total'] ?? count($list);
            $totalPages = $pageData['pageInfo']['lastPage'] ?? ceil($total / $limit);

            return [
                'data' => $list,
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'totalPages' => max(1, $totalPages),
            ];
        });
    }

    /**
     * Get detail of a single manga by ID.
     */
    public function getDetail(string $id): ?array
    {
        // Strip optional prefix
        $numericId = (int) preg_replace('/[^0-9]/', '', $id);
        if ($numericId <= 0) {
            return null;
        }

        $cacheKey = "anilist_detail_{$numericId}";

        return Cache::remember($cacheKey, 3600, function () use ($numericId) {
            $gql = '
            query ($id: Int) {
              Media(id: $id, type: MANGA) {
                id
                title {
                  romaji
                  english
                  native
                }
                synonyms
                description
                coverImage {
                  extraLarge
                  large
                  medium
                }
                bannerImage
                status
                startDate {
                  year
                }
                genres
                staff(perPage: 5) {
                  nodes {
                    name {
                      full
                    }
                  }
                }
              }
            }';

            $response = $this->query($gql, ['id' => $numericId]);
            $media = $response['data']['Media'] ?? null;

            if (!$media) {
                return null;
            }

            return $this->formatMangaCard($media);
        });
    }

    /**
     * Format raw AniList media into unified Manga Card structure.
     */
    public function formatMangaCard(array $m): array
    {
        $id = 'al_' . $m['id'];
        $title = $m['title']['english'] 
            ?? $m['title']['romaji'] 
            ?? $m['title']['native'] 
            ?? 'Untitled';

        $altTitles = array_filter(array_unique(array_merge(
            array_values($m['title'] ?? []),
            $m['synonyms'] ?? []
        )));

        // Clean HTML description
        $rawDesc = (string) ($m['description'] ?? '');
        $cleanDesc = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $rawDesc));
        $cleanDesc = html_entity_decode(trim($cleanDesc));
        if ($cleanDesc === '') {
            $cleanDesc = 'Tidak ada sinopsis.';
        }

        // Cover resolution
        $cover = $m['coverImage']['large'] 
            ?? $m['coverImage']['extraLarge'] 
            ?? $m['coverImage']['medium'] 
            ?? null;

        // Status mapping
        $rawStatus = $m['status'] ?? 'RELEASING';
        $status = match ($rawStatus) {
            'FINISHED' => 'Completed',
            'CANCELLED' => 'Cancelled',
            'HIATUS' => 'Hiatus',
            default => 'Ongoing',
        };

        // Staff
        $staffNames = [];
        foreach ($m['staff']['nodes'] ?? [] as $st) {
            if (!empty($st['name']['full'])) {
                $staffNames[] = $st['name']['full'];
            }
        }
        $authorStr = !empty($staffNames) ? implode(', ', $staffNames) : 'Official Creator';

        $rawFormat = strtoupper($m['format'] ?? 'MANGA');
        $format = str_contains($rawFormat, 'NOVEL') ? 'NOVEL' : 'MANGA';

        return [
            'id' => $id,
            'anilistId' => $m['id'],
            'format' => $format,
            'title' => $title,
            'altTitles' => $altTitles,
            'description' => $cleanDesc,
            'coverUrl' => $cover,
            'status' => $status,
            'year' => $m['startDate']['year'] ?? null,
            'contentRating' => 'Safe',
            'tags' => $m['genres'] ?? ['Manga'],
            'authors' => $authorStr,
            'artists' => $authorStr,
            'hasIndo' => true,
            'hasEnglish' => true,
            'externalLinks' => [],
        ];
    }
}

