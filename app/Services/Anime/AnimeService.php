<?php

namespace App\Services\Anime;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Anime catalogue backed by the AniList GraphQL API (type: ANIME).
 *
 * Mirrors the proven MangaService/AniListProvider patterns on purpose:
 * short timeouts, aggressive Cache::remember TTLs and a normalized card
 * shape, so the anime mode inherits the manga mode's speed profile and
 * never makes a page wait on a slow upstream.
 */
class AnimeService
{
    protected string $endpoint = 'https://graphql.anilist.co';

    protected function query(string $query, array $variables = []): ?array
    {
        $payload = json_encode(['query' => $query, 'variables' => $variables]);

        $ch = curl_init($this->endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
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
            Log::warning("AniList anime GraphQL failed ({$httpCode}): {$err}");
            return null;
        }

        return json_decode($res, true);
    }

    /**
     * Current AniList season (WINTER/SPRING/SUMMER/FALL) + its year.
     * December already belongs to next year's WINTER season.
     */
    public function currentSeason(): array
    {
        $month = (int) date('n');
        $year = (int) date('Y');

        if ($month === 12) {
            return ['season' => 'WINTER', 'year' => $year + 1];
        }
        if ($month <= 2) {
            return ['season' => 'WINTER', 'year' => $year];
        }
        if ($month <= 5) {
            return ['season' => 'SPRING', 'year' => $year];
        }
        if ($month <= 8) {
            return ['season' => 'SUMMER', 'year' => $year];
        }
        return ['season' => 'FALL', 'year' => $year];
    }

    /**
     * Paginated catalogue. Categories:
     * popular | trending | season (terbaru musim ini) | top | ongoing | completed | upcoming
     */
    public function getPopular(int $page = 1, int $limit = 24, ?string $category = 'popular', ?string $genre = null): array
    {
        $category = in_array($category, ['popular', 'trending', 'season', 'top', 'ongoing', 'completed', 'upcoming'], true) ? $category : 'popular';
        $cacheKey = "anilist_anime_catalog_{$category}_{$page}_{$limit}_" . md5((string) $genre);

        return Cache::remember($cacheKey, 21600, function () use ($page, $limit, $category, $genre) {
            $variables = ['page' => $page, 'perPage' => $limit, 'type' => 'ANIME'];

            switch ($category) {
                case 'trending':
                    $variables['sort'] = ['TRENDING_DESC'];
                    break;
                case 'season':
                    $s = $this->currentSeason();
                    $variables['season'] = $s['season'];
                    $variables['seasonYear'] = $s['year'];
                    $variables['sort'] = ['POPULARITY_DESC'];
                    break;
                case 'top':
                    $variables['sort'] = ['SCORE_DESC'];
                    break;
                case 'ongoing':
                    $variables['status'] = 'RELEASING';
                    $variables['sort'] = ['POPULARITY_DESC'];
                    break;
                case 'completed':
                    $variables['status'] = 'FINISHED';
                    $variables['sort'] = ['POPULARITY_DESC'];
                    break;
                case 'upcoming':
                    $variables['status'] = 'NOT_YET_RELEASED';
                    $variables['sort'] = ['POPULARITY_DESC'];
                    break;
                default:
                    $variables['sort'] = ['POPULARITY_DESC'];
            }

            if ($genre) {
                $variables['genre'] = $genre;
            }

            return $this->fetchPage($variables);
        });
    }

    public function search(string $query, int $page = 1, int $limit = 24, ?string $genre = null): array
    {
        $cacheKey = 'anilist_anime_search_' . md5("{$query}_{$page}_{$limit}_{$genre}");

        return Cache::remember($cacheKey, 3600, function () use ($query, $page, $limit, $genre) {
            $variables = [
                'page' => $page,
                'perPage' => $limit,
                'search' => $query,
                'type' => 'ANIME',
                'sort' => ['SEARCH_MATCH'],
            ];
            if ($genre) {
                $variables['genre'] = $genre;
            }
            return $this->fetchPage($variables);
        });
    }

    protected function fetchPage(array $variables): array
    {
        $page = $variables['page'] ?? 1;
        $limit = $variables['perPage'] ?? 24;
        $empty = ['data' => [], 'total' => 0, 'page' => $page, 'limit' => $limit, 'totalPages' => 1];

        $gql = '
        query ($page: Int, $perPage: Int, $type: MediaType, $sort: [MediaSort], $status: MediaStatus, $genre: String, $season: MediaSeason, $seasonYear: Int, $search: String) {
          Page(page: $page, perPage: $perPage) {
            pageInfo { total perPage currentPage lastPage hasNextPage }
            media(type: $type, sort: $sort, status: $status, genre: $genre, season: $season, seasonYear: $seasonYear, search: $search, isAdult: false) {
              id
              idMal
              format
              title { romaji english native }
              synonyms
              description
              coverImage { extraLarge large medium }
              bannerImage
              trailer { id site thumbnail }
              status
              episodes
              duration
              averageScore
              season
              seasonYear
              startDate { year }
              genres
            }
          }
        }';

        $response = $this->query($gql, $variables);
        $pageData = $response['data']['Page'] ?? null;
        if (!$pageData || empty($pageData['media'])) {
            return $empty;
        }

        $list = array_map([$this, 'formatAnimeCard'], $pageData['media']);
        $total = $pageData['pageInfo']['total'] ?? count($list);
        $totalPages = $pageData['pageInfo']['lastPage'] ?? (int) ceil($total / max(1, $limit));

        return [
            'data' => $list,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'totalPages' => max(1, $totalPages),
        ];
    }

    public function getDetail(string $id): ?array
    {
        $numericId = (int) preg_replace('/[^0-9]/', '', $id);
        if ($numericId <= 0) {
            return null;
        }

        return Cache::remember("anime_detail_v2_{$numericId}", 86400, function () use ($numericId) {
            $gql = '
            query ($id: Int) {
              Media(id: $id, type: ANIME) {
                id
                idMal
                format
                title { romaji english native }
                synonyms
                description
                coverImage { extraLarge large medium }
                bannerImage
                trailer { id site thumbnail }
                status
                episodes
                duration
                averageScore
                popularity
                season
                seasonYear
                startDate { year month day }
                endDate { year month day }
                genres
                studios(isMain: true) { nodes { name } }
                nextAiringEpisode { episode timeUntilAiring }
              }
            }';

            $response = $this->query($gql, ['id' => $numericId]);
            $media = $response['data']['Media'] ?? null;
            if (!$media) {
                return null;
            }

            $card = $this->formatAnimeCard($media);
            $card['studios'] = array_values(array_map(fn ($n) => $n['name'] ?? '', $media['studios']['nodes'] ?? []));
            $card['nextAiring'] = $media['nextAiringEpisode'] ?? null;
            return $card;
        });
    }

    /**
     * All home sections in ONE AniList request (GraphQL aliases).
     * Four sequential calls measured ~3.2s cold; a single aliased document
     * brings that to one round trip. Falls back to the per-category calls
     * when the combined query fails for any reason.
     */
    public function homeSections(): array
    {
        $season = $this->currentSeason();
        $cacheKey = 'anilist_anime_home_' . $season['season'] . '_' . $season['year'];

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $fields = '
              id
              idMal
              format
              title { romaji english native }
              synonyms
              description
              coverImage { extraLarge large medium }
              bannerImage
              trailer { id site thumbnail }
              status
              episodes
              duration
              averageScore
              season
              seasonYear
              startDate { year }
              genres';

        $gql = 'query {
          trending: Page(page: 1, perPage: 10) {
            media(type: ANIME, sort: TRENDING_DESC, isAdult: false) {' . $fields . '}
          }
          season: Page(page: 1, perPage: 12) {
            media(type: ANIME, season: ' . $season['season'] . ', seasonYear: ' . (int) $season['year'] . ', sort: POPULARITY_DESC, isAdult: false) {' . $fields . '}
          }
          top: Page(page: 1, perPage: 12) {
            media(type: ANIME, sort: SCORE_DESC, isAdult: false) {' . $fields . '}
          }
          upcoming: Page(page: 1, perPage: 10) {
            media(type: ANIME, status: NOT_YET_RELEASED, sort: POPULARITY_DESC, isAdult: false) {' . $fields . '}
          }
        }';

        $response = $this->query($gql);
        $data = $response['data'] ?? null;

        $sections = null;
        if (is_array($data)) {
            $sections = [];
            foreach (['trending', 'season', 'top', 'upcoming'] as $name) {
                $media = $data[$name]['media'] ?? null;
                $sections[$name] = is_array($media) ? array_map([$this, 'formatAnimeCard'], $media) : [];
            }
            if (empty($sections['trending']) && empty($sections['season'])) {
                $sections = null;
            }
        }

        // Fallback: per-category calls (each one is cached independently).
        if ($sections === null) {
            $sections = [
                'trending' => $this->getPopular(1, 10, 'trending')['data'] ?? [],
                'season' => $this->getPopular(1, 12, 'season')['data'] ?? [],
                'top' => $this->getPopular(1, 12, 'top')['data'] ?? [],
                'upcoming' => $this->getPopular(1, 10, 'upcoming')['data'] ?? [],
            ];
        }

        Cache::put($cacheKey, $sections, 3600);
        return $sections;
    }

    /**
     * Enrich the first $limit cards with a true HD landscape image for the
     * hero slider. Priority: AniList banner (wide) > YouTube trailer still
     * maxresdefault (1280x720, verified via content length so YouTube's
     * tiny placeholder is rejected) > AniList trailer thumbnail > portrait
     * cover. Jikan was evaluated and dropped: api.jikan.moe times out at
     * connection level from the user's network, so it can never serve the
     * hero. Verification runs in parallel; every outcome (hit or miss) is
     * cached 7 days, so this costs ~nothing after the first home load.
     */
    public function enrichHeroImages(array $cards, int $limit = 6): array
    {
        $hero = [];
        $pending = [];

        foreach (array_slice($cards, 0, $limit) as $card) {
            $card['heroUrl'] = $card['bannerUrl'] ?? null;
            if (!$card['heroUrl'] && !empty($card['trailerId'])) {
                $anilistId = $card['anilistId'];
                $cached = Cache::get('anime_hero_img_' . $anilistId);
                if (is_string($cached) && $cached !== '') {
                    $card['heroUrl'] = $cached;
                } elseif ($cached === null) {
                    $pending[$anilistId] = ['index' => count($hero), 'yt' => $card['trailerId']];
                }
            }
            if (!$card['heroUrl']) {
                $card['heroUrl'] = $card['trailerThumb'] ?? $card['coverUrl'] ?? null;
            }
            $hero[] = $card;
        }

        if ($pending) {
            $resolved = $this->resolveYoutubeStills($pending);
            foreach ($resolved as $anilistId => $url) {
                Cache::put('anime_hero_img_' . $anilistId, $url ?? '', 604800);
                if ($url) {
                    $hero[$pending[$anilistId]['index']]['heroUrl'] = $url;
                }
            }
        }

        return $hero;
    }

    /** HEAD-check YouTube maxres stills in parallel; returns anilistId => url|null. */
    protected function resolveYoutubeStills(array $pending): array
    {
        $result = [];
        $mh = curl_multi_init();
        $handles = [];
        foreach ($pending as $anilistId => $info) {
            $ch = curl_init('https://i.ytimg.com/vi/' . $info['yt'] . '/maxresdefault.jpg');
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$anilistId] = $ch;
        }
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.2);
            }
        } while ($running > 0);
        foreach ($handles as $anilistId => $ch) {
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $len = (float) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
            // A real maxres still is tens of KB; YouTube's missing-image
            // placeholder is only ~1-2 KB despite returning HTTP 200.
            $result[$anilistId] = ($code === 200 && $len > 15000)
                ? 'https://i.ytimg.com/vi/' . $pending[$anilistId]['yt'] . '/maxresdefault.jpg'
                : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $result;
    }

    public function getGenres(): array
    {
        return ['Action', 'Adventure', 'Comedy', 'Drama', 'Fantasy', 'Horror', 'Mecha', 'Music', 'Mystery', 'Psychological', 'Romance', 'Sci-Fi', 'Slice of Life', 'Sports', 'Supernatural', 'Thriller'];
    }

    public function formatAnimeCard(array $m): array
    {
        $title = $m['title']['english'] ?? $m['title']['romaji'] ?? $m['title']['native'] ?? 'Untitled';
        $altTitles = array_values(array_filter(array_unique(array_merge(array_values($m['title'] ?? []), $m['synonyms'] ?? []))));

        $titles = array_filter([
            'romaji' => $m['title']['romaji'] ?? null,
            'english' => $m['title']['english'] ?? null,
            'native' => $m['title']['native'] ?? null,
        ]);
        $seenNames = array_map(fn ($t) => mb_strtolower(trim((string) $t)), array_values($titles));
        $synonyms = [];
        foreach (($m['synonyms'] ?? []) as $syn) {
            $syn = trim((string) $syn);
            $key = mb_strtolower($syn);
            if ($syn === '' || in_array($key, $seenNames, true)) {
                continue;
            }
            $seenNames[] = $key;
            $synonyms[] = $syn;
        }

        $rawDesc = (string) ($m['description'] ?? '');
        $cleanDesc = html_entity_decode(trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $rawDesc))));
        if ($cleanDesc === '') {
            $cleanDesc = 'Tidak ada sinopsis.';
        }

        $statusMap = [
            'RELEASING' => 'Ongoing',
            'FINISHED' => 'Tamat',
            'NOT_YET_RELEASED' => 'Segera Tayang',
            'CANCELLED' => 'Dibatalkan',
            'HIATUS' => 'Hiatus',
        ];

        return [
            'id' => 'al_' . $m['id'],
            'anilistId' => (int) $m['id'],
            'malId' => isset($m['idMal']) && $m['idMal'] ? (int) $m['idMal'] : null,
            'title' => $title,
            'altTitles' => $altTitles,
            'titles' => $titles,
            'synonyms' => $synonyms,
            'description' => $cleanDesc,
            'coverUrl' => $m['coverImage']['extraLarge'] ?? $m['coverImage']['large'] ?? $m['coverImage']['medium'] ?? '',
            'bannerUrl' => $m['bannerImage'] ?? null,
            'trailerId' => (($m['trailer']['site'] ?? '') === 'youtube' && !empty($m['trailer']['id'])) ? $m['trailer']['id'] : null,
            'trailerThumb' => $m['trailer']['thumbnail'] ?? null,
            'heroUrl' => null,
            'status' => $statusMap[$m['status'] ?? ''] ?? ($m['status'] ?? 'Unknown'),
            'statusRaw' => $m['status'] ?? null,
            'format' => $m['format'] ?? 'TV',
            'year' => $m['startDate']['year'] ?? $m['seasonYear'] ?? null,
            'season' => isset($m['season']) ? ucfirst(strtolower($m['season'])) . ' ' . ($m['seasonYear'] ?? '') : null,
            'episodes' => $m['episodes'] ?? null,
            'duration' => $m['duration'] ?? null,
            'score' => isset($m['averageScore']) && $m['averageScore'] ? round($m['averageScore'] / 10, 1) : null,
            'genres' => $m['genres'] ?? [],
            'tags' => $m['genres'] ?? [],
        ];
    }
}
