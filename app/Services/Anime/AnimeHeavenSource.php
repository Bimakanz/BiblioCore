<?php

namespace App\Services\Anime;

use App\Services\Anime\Concerns\MatchesTitles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AnimeHeaven: English hard-subtitled MP4 playback.
 *
 * Unlike the HLS providers, AnimeHeaven ships one video file per episode with
 * the subtitles already burned into the frames (typical release:
 * "[SubsPlease] Sousou no Frieren - 28 (1080p)"). That makes it the reliable
 * fallback whenever a soft-sub provider has no English track, at the cost of
 * not being able to toggle or offset the subtitles.
 *
 * Flow:
 *   search.php?s={title}            -> anime.php?{id}
 *   anime.php?{id}                  -> one 32-hex gate id per episode
 *   gate.php with cookie key={gate} -> <source src=".../video.mp4?...">
 *
 * Episode references look like: ah|{animeId}|{episodeNumber}
 */
class AnimeHeavenSource
{
    use MatchesTitles;

    protected const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    protected ?string $base;

    public function __construct()
    {
        $base = trim((string) env('ANIMEHEAVEN_URL', 'https://animeheaven.me'));
        $this->base = $base === '' ? null : rtrim($base, '/');
    }

    public function enabled(): bool
    {
        return $this->base !== null;
    }

    /**
     * Episode list in the shape AnimeStreamProvider expects.
     *
     * @param  array<string, mixed>  $anime
     * @return array<int, array<string, mixed>>
     */
    public function episodesForAniList(int $anilistId, array $anime = []): array
    {
        $series = $this->series($anime);
        if ($series === null) {
            return [];
        }

        $out = [];
        foreach ($series['episodes'] as $number => $gate) {
            $out[] = [
                'id' => 'ah|'.$series['id'].'|'.$number,
                'number' => (int) $number,
                'title' => '',
                'image' => null,
                'duration' => null,
            ];
        }

        return $out;
    }

    /**
     * Resolve an "ah|{animeId}|{number}" reference into direct MP4 mirrors.
     *
     * @return array<string, mixed>|null
     */
    public function streamFor(string $reference): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $parts = explode('|', $reference);
        if (count($parts) < 3 || $parts[0] !== 'ah') {
            return null;
        }

        $id = (string) preg_replace('/[^a-z0-9]/i', '', $parts[1]);
        $number = (int) $parts[2];
        if ($id === '' || $number < 1) {
            return null;
        }

        $episodes = $this->episodesForId($id);
        if (! isset($episodes[$number])) {
            return null;
        }

        return $this->resolve($id, $number, $episodes[$number]);
    }

    /**
     * Resolve an episode number for a title. Used when the primary resolver
     * produced nothing and the caller only knows the catalogue row.
     *
     * @param  array<string, mixed>  $anime
     * @return array<string, mixed>|null
     */
    public function streamForEpisode(array $anime, int $number): ?array
    {
        if ($number < 1) {
            return null;
        }

        $series = $this->series($anime);
        if ($series === null || ! isset($series['episodes'][$number])) {
            return null;
        }

        return $this->resolve($series['id'], $number, $series['episodes'][$number]);
    }

    /**
     * @param  array<string, mixed>  $anime
     * @return array{id: string, episodes: array<int, string>}|null
     */
    protected function series(array $anime): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $id = $this->resolveId($anime);
        if ($id === null) {
            return null;
        }

        $episodes = $this->episodesForId($id);
        if (empty($episodes)) {
            return null;
        }

        return ['id' => $id, 'episodes' => $episodes];
    }

    /**
     * Map a catalogue row to an AnimeHeaven id.
     *
     * Search results are scored against every candidate title, not only the
     * query that returned them: the site indexes English names while AniList
     * often answers with the romaji one.
     *
     * @param  array<string, mixed>  $anime
     */
    protected function resolveId(array $anime): ?string
    {
        $titles = $this->titleCandidates($anime, 4);
        if (empty($titles)) {
            return null;
        }

        $cacheKey = 'animeheaven_id_v2_'.md5(implode('|', $titles));
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        $best = null;
        $bestScore = 0.0;
        $anchor = $this->anchorToken((string) ($titles[0] ?? ''));
        foreach ($titles as $title) {
            foreach ($this->searchQueries($title) as $query) {
                foreach ($this->search($query) as $rank => $row) {
                    $hitTokens = $this->tokens($this->normaliseTitle($row['title']));
                    $firstHitSharesAnchor = $rank === 0 && $anchor !== '' && in_array($anchor, $hitTokens, true);
                    foreach ($titles as $candidate) {
                        $score = $this->titleScore($candidate, $row['title']);
                        if ($firstHitSharesAnchor) {
                            $score = max($score, 0.7);
                        }
                        $score = $this->adjustForSeason($candidate, $row['title'], $score);
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $best = $row['id'];
                        }
                    }
                }
                if ($bestScore >= 0.92) {
                    break;
                }
            }
            if ($bestScore >= 0.92) {
                break;
            }
        }

        if ($best === null || $bestScore < 0.62) {
            return null;
        }

        Cache::put($cacheKey, $best, 604800);

        return $best;
    }

    /**
     * @return array<int, array{id: string, title: string}>
     */
    protected function search(string $title): array
    {
        if (! $this->enabled() || trim($title) === '') {
            return [];
        }

        $cacheKey = 'animeheaven_search_'.md5(strtolower(trim($title)));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $html = $this->fetch($this->base.'/search.php?s='.urlencode($title));
        if ($html === null) {
            return [];
        }

        preg_match_all(
            "#href='anime\.php\?([a-z0-9]+)'[^>]*>\s*<img[^>]*class='coverimg'[^>]*alt='([^']*)'#i",
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $rows = [];
        foreach ($matches as $row) {
            $id = (string) $row[1];
            if (isset($rows[$id])) {
                continue;
            }
            $rows[$id] = [
                'id' => $id,
                'title' => html_entity_decode((string) $row[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }

        if (! empty($rows)) {
            Cache::put($cacheKey, array_values($rows), 86400);
        }

        return array_values($rows);
    }

    /**
     * @return array<int, string> episode number => gate id, ascending
     */
    protected function episodesForId(string $id): array
    {
        $cacheKey = 'animeheaven_episodes_'.$id;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $html = $this->fetch($this->base.'/anime.php?'.$id);
        if ($html === null) {
            return [];
        }

        preg_match_all(
            '#gatea\(\s*"([0-9a-f]{32})"\).*?watch2[^>]*>\s*(\d+)\s*<#is',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $episodes = [];
        foreach ($matches as $row) {
            $number = (int) $row[2];
            if ($number > 0 && ! isset($episodes[$number])) {
                $episodes[$number] = $row[1];
            }
        }

        ksort($episodes);

        if (! empty($episodes)) {
            Cache::put($cacheKey, $episodes, 21600);
        }

        return $episodes;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolve(string $id, int $number, string $gate): ?array
    {
        $cacheKey = "animeheaven_stream_{$id}_{$number}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $html = $this->fetch($this->base.'/gate.php', [
            'Cookie: key='.$gate,
            'Referer: '.$this->base.'/anime.php?'.$id,
        ]);
        if ($html === null) {
            return null;
        }

        preg_match_all("#<source\\s+src='([^']+\\.mp4[^']*)'#i", $html, $matches);
        $urls = [];
        foreach ($matches[1] as $url) {
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (! isset($urls[$url])) {
                $urls[$url] = $this->mirrorRank($url);
            }
        }

        if (empty($urls)) {
            return null;
        }

        asort($urls);

        $stream = [
            'sources' => array_map(
                fn (string $url) => ['url' => $url, 'quality' => 'auto', 'isM3U8' => false],
                array_keys($urls)
            ),
            'subtitles' => [],
            'kind' => 'mp4',
            'embed' => null,
            'referer' => null,
            'intro' => null,
            'outro' => null,
            'provider' => 'AnimeHeaven',
            'server' => 'Hardsub EN',
            'audio_fallback' => null,
            'hard_sub' => true,
        ];

        // Gate links are stable per episode, but a rotated mirror is cheap to
        // re-read, so an hour is enough.
        Cache::put($cacheKey, $stream, 3600);

        return $stream;
    }

    /** Lower is better: the first mirror in AnimeHeaven's own player order. */
    protected function mirrorRank(string $url): int
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (['ci.', 'ct.', 'ck.', 'ax.'] as $rank => $prefix) {
            if (str_starts_with($host, $prefix)) {
                return $rank;
            }
        }

        return 9;
    }

    /**
     * @param  array<int, string>  $headers
     */
    protected function fetch(string $url, array $headers = [], int $timeout = 12): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_HTTPHEADER => array_merge(['Accept: text/html,application/xhtml+xml'], $headers),
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 400) {
            Log::debug('AnimeHeaven request failed', ['url' => $url, 'status' => $code]);

            return null;
        }

        return (string) $body;
    }
}
