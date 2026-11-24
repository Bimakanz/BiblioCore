<?php

namespace App\Services\Anime;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anikoto episode catalogue + MegaPlay embed playback.
 *
 * Anikoto (anikotoapi.site) is the maintained successor of the HiAnime
 * library: every episode row carries a MegaPlay embed id, and the series
 * payload also exposes the matching AniList (`ani_id`) and MAL (`mal_id`)
 * mapping. That makes it a dependable fallback when the Anivexa resolver has
 * no source for a freshly airing cour, or no dub track at all.
 *
 * The public API has no search route, so this class keeps a small title index
 * built from the `/recent-anime` feed (100 titles per page). Only the most
 * recently updated pages are indexed, which is exactly the window where the
 * primary resolver tends to lag; older titles keep using Anivexa.
 *
 * Rate limit: 60 requests / 120 s per IP. The index is fetched in one parallel
 * wave and cached for 12 hours, so a normal request costs at most two calls.
 *
 * Episode references look like: ak|{anikotoId}|{episodeNumber}|{sub|dub}
 */
class AnikotoSource
{
    protected ?string $base;

    /** How many `/recent-anime` pages to index (100 titles each). */
    protected int $pages;

    protected const INDEX_KEY = 'anikoto_index_v1';

    protected const INDEX_TTL = 43200;

    protected const SERIES_TTL = 21600;

    public function __construct()
    {
        $base = trim((string) env('ANIKOTO_API_URL', 'https://anikotoapi.site'));
        $this->base = $base === '' ? null : rtrim($base, '/');
        $this->pages = max(1, min(60, (int) env('ANIKOTO_INDEX_PAGES', 12)));
    }

    public function enabled(): bool
    {
        return $this->base !== null;
    }

    /**
     * Episode list for an AniList id, in the shape AnimeStreamProvider uses.
     *
     * @param  array<string, mixed>  $anime  catalogue row (title/altTitles)
     * @return array<int, array<string, mixed>>
     */
    public function episodesForAniList(int $anilistId, string $audio = 'sub', array $anime = []): array
    {
        $audio = $audio === 'dub' ? 'dub' : 'sub';
        if (! $this->enabled() || $anilistId <= 0) {
            return [];
        }

        $seriesId = $this->seriesIdFor($anilistId, $anime);
        if ($seriesId === null) {
            return [];
        }

        $series = $this->series($seriesId);
        if (! is_array($series)) {
            return [];
        }

        $poster = $series['anime']['poster'] ?? null;
        $minutes = (int) ($series['anime']['duration'] ?? 0);

        $out = [];
        foreach (($series['episodes'] ?? []) as $episode) {
            if (! is_array($episode)) {
                continue;
            }

            $number = (int) ($episode['number'] ?? 0);
            if ($number <= 0) {
                continue;
            }

            $embed = $this->embedUrl($episode, $audio);
            if ($embed === null) {
                continue;
            }

            $out[] = [
                'id' => 'ak|'.$seriesId.'|'.$number.'|'.$audio,
                'number' => $number,
                'title' => (string) ($episode['title'] ?? ''),
                'image' => is_string($poster) ? $poster : null,
                'duration' => $minutes > 0 ? $minutes * 60 : null,
            ];
        }

        usort($out, fn ($a, $b) => $a['number'] <=> $b['number']);

        return $out;
    }

    /**
     * Resolve an `ak|...` reference into a MegaPlay embed.
     *
     * @return array<string, mixed>|null
     */
    public function streamFor(string $reference): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $parts = explode('|', $reference);
        if (count($parts) < 4 || $parts[0] !== 'ak') {
            return null;
        }

        $seriesId = (int) $parts[1];
        $number = (int) $parts[2];
        $audio = ($parts[3] ?? 'sub') === 'dub' ? 'dub' : 'sub';
        if ($seriesId <= 0 || $number <= 0) {
            return null;
        }

        $series = $this->series($seriesId);
        if (! is_array($series)) {
            return null;
        }

        foreach (($series['episodes'] ?? []) as $episode) {
            if (! is_array($episode) || (int) ($episode['number'] ?? 0) !== $number) {
                continue;
            }

            $fallback = null;
            $url = $this->embedUrl($episode, $audio);
            if ($url === null && $audio === 'dub') {
                $url = $this->embedUrl($episode, 'sub');
                $fallback = $url === null ? null : 'sub';
            }
            if ($url === null) {
                return null;
            }

            return [
                'sources' => [[
                    'url' => $url,
                    'quality' => 'auto',
                    'isM3U8' => false,
                ]],
                'subtitles' => [],
                'kind' => 'embed',
                'embed' => $url,
                'referer' => null,
                'intro' => null,
                'outro' => null,
                'provider' => 'Anikoto',
                'server' => 'MegaPlay',
                'audio_fallback' => $fallback,
            ];
        }

        return null;
    }

    /** MegaPlay embed URL for one episode, or null when the track is absent. */
    protected function embedUrl(array $episode, string $audio): ?string
    {
        $url = $episode['embed_url'][$audio] ?? null;
        if (! is_string($url) || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        return str_starts_with($url, 'http') ? $url : 'https://'.$url;
    }

    /**
     * Map an AniList id to an Anikoto series id.
     *
     * @param  array<string, mixed>  $anime
     */
    protected function seriesIdFor(int $anilistId, array $anime = []): ?int
    {
        $index = $this->index();

        if (isset($index['by_ani'][$anilistId])) {
            return (int) $index['by_ani'][$anilistId];
        }

        foreach ($this->titleCandidates($anime) as $title) {
            $key = $this->normaliseTitle($title);
            if ($key !== '' && isset($index['by_title'][$key])) {
                return (int) $index['by_title'][$key];
            }
        }

        return null;
    }

    /**
     * Title index built from the recent-anime feed.
     *
     * @return array{by_ani: array<int, int>, by_title: array<string, int>}
     */
    protected function index(): array
    {
        $cached = Cache::get(self::INDEX_KEY);
        if (is_array($cached)) {
            return [
                'by_ani' => is_array($cached['by_ani'] ?? null) ? $cached['by_ani'] : [],
                'by_title' => is_array($cached['by_title'] ?? null) ? $cached['by_title'] : [],
            ];
        }

        $empty = ['by_ani' => [], 'by_title' => []];
        if (! $this->enabled()) {
            return $empty;
        }

        $urls = [];
        for ($page = 1; $page <= $this->pages; $page++) {
            $urls[] = $this->base.'/recent-anime?page='.$page.'&per_page=100';
        }

        $byAni = [];
        $byTitle = [];

        try {
            /** @var array<int, Response|\Throwable> $responses */
            $responses = Http::pool(function ($pool) use ($urls) {
                return array_map(
                    fn (string $url) => $pool->timeout(6)->connectTimeout(3)->acceptJson()->get($url),
                    $urls
                );
            });
        } catch (\Throwable $e) {
            Log::warning('Anikoto index failed: '.$e->getMessage());
            $responses = [];
        }

        foreach ($responses as $response) {
            if (! $response instanceof Response || ! $response->ok()) {
                continue;
            }

            $rows = $response->json('data');
            if (! is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $ani = (int) ($row['ani_id'] ?? 0);
                if ($ani > 0) {
                    $byAni[$ani] = $id;
                }

                $names = [
                    (string) ($row['title'] ?? ''),
                    (string) ($row['alternative'] ?? ''),
                    (string) ($row['native'] ?? ''),
                ];
                foreach (preg_split('/[;,]/', (string) ($row['titles'] ?? '')) ?: [] as $title) {
                    $names[] = trim($title);
                }

                foreach ($names as $name) {
                    $key = $this->normaliseTitle($name);
                    if ($key !== '' && ! isset($byTitle[$key])) {
                        $byTitle[$key] = $id;
                    }
                }
            }
        }

        $index = ['by_ani' => $byAni, 'by_title' => $byTitle];
        // A failed wave must not be remembered for half a day.
        Cache::put(self::INDEX_KEY, $index, empty($byAni) ? 300 : self::INDEX_TTL);

        return $index;
    }

    /**
     * Series payload (anime + episodes) from Anikoto.
     *
     * @return array<string, mixed>|null
     */
    protected function series(int $seriesId): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $key = "anikoto_series_{$seriesId}";
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::timeout(8)->connectTimeout(3)->acceptJson()
                ->get($this->base.'/series/'.$seriesId);
            $data = $response->ok() ? $response->json('data') : null;
        } catch (\Throwable $e) {
            Log::warning("Anikoto series {$seriesId} failed: ".$e->getMessage());

            return null;
        }

        if (! is_array($data) || empty($data['episodes'])) {
            return null;
        }

        Cache::put($key, $data, self::SERIES_TTL);

        return $data;
    }

    /** @return array<int, string> */
    protected function titleCandidates(array $anime): array
    {
        $titles = [(string) ($anime['title'] ?? '')];
        foreach (['titles', 'altTitles'] as $field) {
            foreach ((array) ($anime[$field] ?? []) as $title) {
                $titles[] = (string) $title;
            }
        }

        $out = [];
        foreach ($titles as $title) {
            $title = trim($title);
            if ($title !== '' && ! in_array($title, $out, true)) {
                $out[] = $title;
            }
        }

        return array_slice($out, 0, 4);
    }

    protected function normaliseTitle(string $title): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($title));
    }
}
