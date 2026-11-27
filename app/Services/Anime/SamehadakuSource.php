<?php

namespace App\Services\Anime;

use App\Services\Anime\Concerns\MatchesTitles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Samehadaku: Indonesian hard-subtitled MP4 playback.
 *
 * Samehadaku publishes each episode as an MP4 on its own CDN (720p "MP4HD"
 * and 1080p "FULLHD" variants) with Indonesian subtitles burned in, plus a
 * few iframe mirrors as a fallback. It is the Indonesian counterpart to
 * AnimeHeaven: no subtitle track to sync, no offset to tune, just a file that
 * already reads correctly.
 *
 * Flow:
 *   /?s={title}                       -> derive the series slug
 *   /anime/{slug}/                    -> every episode link
 *   /{slug}-episode-{n}/              -> direct MP4 + iframe mirrors
 *
 * Episode references look like: sd|{slug}|{episodeNumber}
 */
class SamehadakuSource
{
    use MatchesTitles;

    protected const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    protected ?string $base;

    public function __construct()
    {
        $base = trim((string) env('SAMEHADAKU_URL', 'https://v2.samehadaku.how'));
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
    public function episodesFor(array $anime): array
    {
        $slug = $this->resolveSlug($anime);
        if ($slug === null) {
            return [];
        }

        $out = [];
        foreach ($this->seriesEpisodes($slug) as $number => $url) {
            $out[] = [
                'id' => 'sd|'.$slug.'|'.$number,
                'number' => (int) $number,
                'title' => '',
                'image' => null,
                'duration' => null,
            ];
        }

        return $out;
    }

    /**
     * Resolve an "sd|{slug}|{number}" reference.
     *
     * @return array<string, mixed>|null
     */
    public function streamFor(string $reference): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $parts = explode('|', $reference);
        if (count($parts) < 3 || $parts[0] !== 'sd') {
            return null;
        }

        $slug = (string) preg_replace('/[^a-z0-9\-]/i', '', $parts[1]);
        $number = (int) $parts[2];
        if ($slug === '' || $number < 1) {
            return null;
        }

        $url = $this->seriesEpisodes($slug)[$number] ?? null;
        if ($url === null) {
            return null;
        }

        return $this->resolveEpisode($url, $slug);
    }

    /**
     * @param  array<string, mixed>  $anime
     * @return array<string, mixed>|null
     */
    public function streamForEpisode(array $anime, int $number): ?array
    {
        if ($number < 1) {
            return null;
        }

        $slug = $this->resolveSlug($anime);
        if ($slug === null) {
            return null;
        }

        $url = $this->seriesEpisodes($slug)[$number] ?? null;
        if ($url === null) {
            return null;
        }

        return $this->resolveEpisode($url, $slug);
    }

    /**
     * Map a catalogue row to a series slug.
     *
     * The site's WordPress search only matches the words it is given, so the
     * full English title often returns nothing while the romaji one works.
     * Every candidate is tried, and hits are scored against all of them.
     *
     * @param  array<string, mixed>  $anime
     */
    protected function resolveSlug(array $anime): ?string
    {
        $titles = $this->titleCandidates($anime, 4);
        if (empty($titles)) {
            return null;
        }

        $cacheKey = 'samehadaku_slug_v5_'.md5(implode('|', $titles));
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached;
        }

        // Searching the full English title usually returns nothing on this
        // WordPress catalogue, so the distinctive word is the real query.
        $best = null;
        $bestScore = 0.0;
        $anchor = $this->anchorToken((string) ($titles[0] ?? ''));
        foreach ($titles as $title) {
            foreach ($this->searchQueries($title) as $query) {
                foreach ($this->slugsFromSearch($query) as $slug) {
                    $name = str_replace('-', ' ', $slug);
                    $slugTokens = $this->tokens($this->normaliseTitle($name));
                    $sharesAnchor = $this->sharesStrongAnchor($anchor, $slugTokens);
                    foreach ($titles as $candidate) {
                        $score = $this->titleScore($candidate, $name);
                        if ($sharesAnchor) {
                            $score = max($score, 0.68);
                        }
                        $score = $this->adjustForSeason($candidate, $name, $score);
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $best = $slug;
                        }
                    }
                }
                if ($bestScore >= 0.9) {
                    break;
                }
            }
            if ($bestScore >= 0.9) {
                break;
            }
        }

        if ($best === null || $bestScore < 0.6) {
            return null;
        }

        Cache::put($cacheKey, $best, 604800);

        return $best;
    }

    /**
     * @return array<int, string>
     */
    protected function slugsFromSearch(string $query): array
    {
        if (! $this->enabled() || trim($query) === '') {
            return [];
        }

        $cacheKey = 'samehadaku_search_'.md5(strtolower(trim($query)));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $html = $this->fetch($this->base.'/?s='.urlencode($query));
        if ($html === null) {
            return [];
        }

        preg_match_all(
            '#https?://[^"\']*?/([a-z0-9\-]+?)-episode-(\d+)(?:-end)?/?#i',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $slugs = [];
        foreach ($matches as $row) {
            $slugs[$row[1]] = true;
        }
        $slugs = array_keys($slugs);

        if (! empty($slugs)) {
            Cache::put($cacheKey, $slugs, 86400);
        }

        return $slugs;
    }

    /**
     * @return array<int, string> episode number => absolute episode URL
     */
    protected function seriesEpisodes(string $slug): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $cacheKey = 'samehadaku_series_'.$slug;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $html = $this->fetch($this->base.'/anime/'.rawurlencode($slug).'/');
        if ($html === null) {
            return [];
        }

        preg_match_all(
            '#href="([^"]*/'.preg_quote($slug, '#').'-episode-(\d+)(-end)?/?)"#i',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        $episodes = [];
        foreach ($matches as $row) {
            $number = (int) $row[2];
            if ($number < 1 || isset($episodes[$number])) {
                continue;
            }

            $url = html_entity_decode($row[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_starts_with($url, '//')) {
                $url = 'https:'.$url;
            } elseif (str_starts_with($url, '/')) {
                $url = $this->base.$url;
            }
            $episodes[$number] = $url;
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
    protected function resolveEpisode(string $url, string $slug): ?array
    {
        $cacheKey = 'samehadaku_stream_'.md5($url);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $html = $this->fetch($url, ['Referer: '.$this->base.'/anime/'.$slug.'/']);
        if ($html === null) {
            return null;
        }

        // Server options keep their player markup HTML-escaped inside
        // data-embed attributes, so decode once before looking for URLs.
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $sources = $this->mp4Sources($html);
        if (! empty($sources)) {
            $stream = [
                'sources' => $sources,
                'subtitles' => [],
                'kind' => 'mp4',
                'embed' => null,
                'referer' => null,
                'intro' => null,
                'outro' => null,
                'provider' => 'Samehadaku',
                'server' => 'Hardsub ID',
                'audio_fallback' => null,
                'hard_sub' => true,
                'hard_sub_lang' => 'id',
            ];
            Cache::put($cacheKey, $stream, 3600);

            return $stream;
        }

        $embed = $this->embedUrl($html);
        if ($embed === null) {
            return null;
        }

        $stream = [
            'sources' => [['url' => $embed, 'quality' => 'auto', 'isM3U8' => false]],
            'subtitles' => [],
            'kind' => 'embed',
            'embed' => $embed,
            'referer' => null,
            'intro' => null,
            'outro' => null,
            'provider' => 'Samehadaku',
            'server' => 'Hardsub ID',
            'audio_fallback' => null,
            'hard_sub' => true,
            'hard_sub_lang' => 'id',
        ];
        Cache::put($cacheKey, $stream, 3600);

        return $stream;
    }

    /**
     * Direct MP4 mirrors, best quality first.
     *
     * @return array<int, array{url: string, quality: string, isM3U8: bool}>
     */
    protected function mp4Sources(string $html): array
    {
        preg_match_all('#https?://[^\s"\'<>]+\.mp4(?:\?[^\s"\'<>]*)?#i', $html, $matches);

        $found = [];
        foreach ($matches[0] as $url) {
            $url = str_replace('&amp;', '&', $url);
            $quality = $this->qualityFromUrl($url);
            $found[$url] = max($found[$url] ?? 0, $this->qualityRank($quality));
        }

        if (empty($found)) {
            return [];
        }

        arsort($found);

        $out = [];
        foreach (array_keys($found) as $url) {
            $out[] = [
                'url' => $url,
                'quality' => $this->qualityFromUrl($url),
                'isM3U8' => false,
            ];
        }

        return $out;
    }

    protected function qualityFromUrl(string $url): string
    {
        $url = strtolower($url);
        if (str_contains($url, 'fullhd') || str_contains($url, '1080')) {
            return '1080p';
        }
        if (str_contains($url, 'mp4hd') || str_contains($url, '720')) {
            return '720p';
        }
        if (str_contains($url, '480')) {
            return '480p';
        }
        if (str_contains($url, '360')) {
            return '360p';
        }

        return 'auto';
    }

    protected function qualityRank(string $quality): int
    {
        return match ($quality) {
            '1080p' => 4,
            '720p' => 3,
            '480p' => 2,
            '360p' => 1,
            default => 0,
        };
    }

    /**
     * Iframe mirror used when no direct MP4 is present. Only known players
     * are accepted so an ad frame is never mounted in the player.
     */
    protected function embedUrl(string $html): ?string
    {
        preg_match_all('#<iframe[^>]+src="([^"]+)"#i', $html, $matches);

        $preferred = ['wibufile', 'blogger.com', 'mega.nz', 'filedon', 'desustream'];
        $fallback = null;
        foreach (array_unique($matches[1]) as $url) {
            $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            foreach ($preferred as $needle) {
                if (str_contains($host, $needle)) {
                    return $url;
                }
            }
            $fallback ??= $url;
        }

        return $fallback;
    }

    /**
     * @param  array<int, string>  $headers
     */
    protected function fetch(string $url, array $headers = [], int $timeout = 15): ?string
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
            Log::debug('Samehadaku request failed', ['url' => $url, 'status' => $code]);

            return null;
        }

        return (string) $body;
    }
}
