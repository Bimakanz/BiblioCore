<?php

namespace App\Services\Anime;

use App\Services\Anime\Concerns\MatchesTitles;
use App\Services\Anime\Concerns\ResolvesVideoEmbeds;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Animasu: Indonesian hard-subtitled MP4 playback (non-Cloudflare catalogue).
 *
 * Animasu indexes a wide catalogue (including older series such as Danshi
 * Koukousei no Nichijou, Steins;Gate, and One-Punch Man) behind LiteSpeed
 * without Cloudflare bot challenges. Episode pages encode player iframes as
 * base64 <option> values pointing to YourUpload (720p MP4) and Blogger
 * (GoogleVideo MP4), both extractable as direct MP4 files.
 *
 * Flow:
 *   /?s={title}                       -> derive the series slug
 *   /anime/{slug}/                    -> episode page URLs
 *   /nonton-...-episode-{n}/          -> base64 player options -> direct MP4
 *
 * Episode references look like: am|{slug}|{episodeNumber}
 */
class AnimasuSource
{
    use MatchesTitles;
    use ResolvesVideoEmbeds;

    protected const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    protected ?string $base;

    protected ?string $lastError = null;

    public function __construct()
    {
        $base = trim((string) env('ANIMASU_URL', 'https://animasu.love'));
        $this->base = $base === '' ? null : rtrim($base, '/');
    }

    public function enabled(): bool
    {
        return $this->base !== null;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
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
                'id' => 'am|'.$slug.'|'.$number,
                'number' => (int) $number,
                'title' => '',
                'image' => null,
                'duration' => null,
            ];
        }

        return $out;
    }

    /**
     * Resolve an "am|{slug}|{number}" reference.
     *
     * @return array<string, mixed>|null
     */
    public function streamFor(string $reference): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $parts = explode('|', $reference);
        if (count($parts) < 3 || $parts[0] !== 'am') {
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
        $this->lastError = null;
        if (! $this->enabled()) {
            $this->lastError = 'disabled';

            return null;
        }
        if ($number < 1) {
            $this->lastError = 'bad_ep';

            return null;
        }

        $slug = $this->resolveSlug($anime);
        if ($slug === null) {
            $this->lastError = $this->lastError ? 's:'.$this->lastError : 's:no_slug';

            return null;
        }

        $this->lastError = null;
        $url = $this->seriesEpisodes($slug)[$number] ?? null;
        if ($url === null) {
            $this->lastError = $this->lastError ? 'p:'.$this->lastError : 'p:no_ep_'.$number;

            return null;
        }

        $this->lastError = null;
        $stream = $this->resolveEpisode($url, $slug);
        if ($stream === null) {
            $this->lastError = $this->lastError ? 'e:'.$this->lastError : 'e:no_src';

            return null;
        }

        $this->lastError = 'ok';

        return $stream;
    }

    /**
     * @param  array<string, mixed>  $anime
     */
    protected function resolveSlug(array $anime): ?string
    {
        $titles = $this->titleCandidates($anime, 4);
        if (empty($titles)) {
            return null;
        }

        $cacheKey = 'animasu_slug_v1_'.md5(implode('|', $titles));
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
                    $firstHitSharesAnchor = $rank === 0 && $this->sharesStrongAnchor($anchor, $hitTokens);
                    foreach ($titles as $candidate) {
                        $score = $this->titleScore($candidate, $row['title']);
                        if ($firstHitSharesAnchor) {
                            $score = max($score, 0.68);
                        }
                        $score = $this->adjustForSeason($candidate, $row['title'], $score);
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $best = $row['slug'];
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
     * @return array<int, array{slug: string, title: string}>
     */
    protected function search(string $query): array
    {
        if (! $this->enabled() || trim($query) === '') {
            return [];
        }

        $cacheKey = 'animasu_search_v1_'.md5(strtolower(trim($query)));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $html = $this->fetch($this->base.'/?s='.urlencode($query));
        if ($html === null) {
            return [];
        }

        $rows = [];
        if (preg_match_all(
            '#<a[^>]+href="(?:https?://[^/]+)?/anime/([a-z0-9\-]+)/"[^>]*title="([^"]+)"#i',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $row) {
                $slug = (string) $row[1];
                if ($slug === '' || isset($rows[$slug])) {
                    continue;
                }
                $rows[$slug] = [
                    'slug' => $slug,
                    'title' => $this->cleanSearchTitle((string) $row[2]),
                ];
            }
        }

        if (empty($rows) && preg_match_all(
            '#href="(?:https?://[^/]+)?/anime/([a-z0-9\-]+)/"#i',
            $html,
            $matches
        )) {
            foreach (array_unique($matches[1]) as $slug) {
                $rows[$slug] = [
                    'slug' => (string) $slug,
                    'title' => $this->cleanSearchTitle(str_replace('-', ' ', (string) $slug)),
                ];
            }
        }

        $result = array_values($rows);
        if (! empty($result)) {
            Cache::put($cacheKey, $result, 86400);
        }

        return $result;
    }

    protected function cleanSearchTitle(string $raw): string
    {
        $title = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = (string) preg_replace('/^nonton\s+anime\s+/i', '', $title);
        $title = (string) preg_replace('/\s+sub(?:title)?\s+indo(?:nesia)?$/i', '', $title);

        return trim($title);
    }

    /**
     * @return array<int, string> episode number => absolute episode URL
     */
    protected function seriesEpisodes(string $slug): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $cacheKey = 'animasu_series_v1_'.$slug;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $html = $this->fetch($this->base.'/anime/'.rawurlencode($slug).'/');
        if ($html === null) {
            return [];
        }

        preg_match_all(
            '#href="((?:https?://[^/]+)?/nonton-[^"]*?(?:-episode|-ep)-(\d+)(?:-[^"/]*)?/?)"#i',
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
            if (str_starts_with($url, '/')) {
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
        $cacheKey = 'animasu_stream_v1_'.md5($url);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $html = $this->fetch($url, ['Referer: '.$this->base.'/anime/'.$slug.'/']);
        if ($html === null) {
            return null;
        }

        $embedCandidates = $this->extractEmbedUrls($html);
        $sources = [];
        $seen = [];

        $fetcher = fn (string $target, array $headers = []) => $this->fetch($target, $headers);

        // 1. Direct MP4 URLs already inside the decoded player options.
        foreach ($embedCandidates as $candidate) {
            if (preg_match('#^https?://[^\s"\'<>]+\.mp4(?:\?[^\s"\'<>]*)?$#i', $candidate)
                && ! str_contains(strtolower($candidate), 'uservideo.')) {
                $seen[$candidate] = true;
                $sources[] = [
                    'url' => $candidate,
                    'quality' => $this->qualityFromText($candidate),
                    'isM3U8' => false,
                    'referer' => $url,
                ];
            }
        }

        // 2. YourUpload (720p vidcache.net MP4).
        foreach ($embedCandidates as $candidate) {
            if (! str_contains(strtolower($candidate), 'yourupload.com/embed/')) {
                continue;
            }
            $yu = $this->extractYourUploadMp4($candidate, $url, $fetcher);
            if ($yu !== null && ! isset($seen[$yu['url']])) {
                $seen[$yu['url']] = true;
                $sources[] = $yu;
                break;
            }
        }

        // 3. Blogger (GoogleVideo MP4, works reliably from datacenter/Vercel IPs).
        foreach ($embedCandidates as $candidate) {
            if (! str_contains(strtolower($candidate), 'blogger.com/video.g')) {
                continue;
            }
            foreach ($this->extractBloggerMp4s($candidate, $fetcher) as $entry) {
                if (! isset($seen[$entry['url']])) {
                    $seen[$entry['url']] = true;
                    $sources[] = $entry;
                }
            }
            if (! empty($sources)) {
                break;
            }
        }

        if (empty($sources)) {
            return null;
        }

        $stream = [
            'sources' => array_slice($sources, 0, 3),
            'subtitles' => [],
            'kind' => 'mp4',
            'embed' => null,
            'referer' => null,
            'intro' => null,
            'outro' => null,
            'provider' => 'Animasu',
            'server' => 'Hardsub ID',
            'audio_fallback' => null,
            'hard_sub' => true,
            'hard_sub_lang' => 'id',
            'proxy_file' => true,
        ];

        Cache::put($cacheKey, $stream, 3600);

        return $stream;
    }

    /**
     * Decode all base64 <option value="..."> player snippets plus visible iframes.
     *
     * @return array<int, string>
     */
    protected function extractEmbedUrls(string $html): array
    {
        $urls = [];
        $seen = [];

        if (preg_match_all('#<option[^>]+value="([A-Za-z0-9+/=]{16,})"[^>]*>#i', $html, $options)) {
            foreach ($options[1] as $b64) {
                $decoded = base64_decode($b64, true);
                if (! is_string($decoded) || $decoded === '') {
                    continue;
                }
                if (preg_match('#src=["\']([^"\']+)["\']#i', $decoded, $m)) {
                    $src = $this->normaliseEmbedUrl($m[1]);
                    if ($src !== null && ! isset($seen[$src])) {
                        $seen[$src] = true;
                        $urls[] = $src;
                    }
                }
            }
        }

        if (preg_match_all('#<iframe[^>]+src=["\']([^"\']+)["\']#i', $html, $iframes)) {
            foreach ($iframes[1] as $raw) {
                $src = $this->normaliseEmbedUrl($raw);
                if ($src !== null && ! isset($seen[$src])) {
                    $seen[$src] = true;
                    $urls[] = $src;
                }
            }
        }

        return $urls;
    }

    protected function normaliseEmbedUrl(string $raw): ?string
    {
        $src = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (str_starts_with($src, '//')) {
            $src = 'https:'.$src;
        }

        return preg_match('#^https?://#i', $src) ? $src : null;
    }

    protected function qualityFromText(string $text): string
    {
        if (preg_match('/\b(1080|720|480|360)p?\b/i', $text, $m)) {
            return $m[1].'p';
        }

        return 'auto';
    }

    /**
     * @param  array<int, string>  $headers
     */
    protected function fetch(string $url, array $headers = [], int $timeout = 15): ?string
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_NOSIGNAL => 1,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => self::UA,
                CURLOPT_HTTPHEADER => array_merge([
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
                ], $headers),
            ];
            if (defined('CURL_HTTP_VERSION_2TLS')) {
                $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
            }
            curl_setopt_array($ch, $opts);

            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            curl_close($ch);

            if ($body !== false && $code >= 200 && $code < 400) {
                return (string) $body;
            }

            $this->lastError = $code > 0 ? 'http_'.$code : 'curl_'.$errno;
            Log::debug('Animasu request failed', ['url' => $url, 'status' => $code, 'errno' => $errno, 'attempt' => $attempt]);

            if ($attempt === 0 && in_array($code, [502, 503, 504], true)) {
                usleep(200_000);
                continue;
            }

            break;
        }

        return null;
    }
}
