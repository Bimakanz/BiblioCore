<?php

namespace App\Services\Anime;

use App\Services\Anime\Concerns\MatchesTitles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Kuramanime: Indonesian hard-subtitled MP4 playback via a Playwright worker.
 *
 * Kuramanime's search (/quicksearch/get) and series pages (/anime/{id}/{slug})
 * are plain JSON/HTML, while its episode video endpoint is gated behind
 * obfuscated client-side JS (leviathan.js + browser fingerprinting). When
 * KURAMANIME_WORKER_URL is configured, stream extraction delegates to the
 * Node/Playwright worker in workers/kuramanime-worker.
 *
 * Episode references look like: km|{animeId}|{slug}|{episodeNumber}
 */
class KuramanimeSource
{
    use MatchesTitles;

    protected const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    protected const FALLBACK_BASE = 'https://v20.kuramanime.ing';

    protected ?string $workerUrl;

    protected ?string $workerKey;

    protected ?string $configuredBase;

    public function __construct()
    {
        $worker = trim((string) env('KURAMANIME_WORKER_URL', ''));
        $worker = $worker === '' ? null : rtrim($worker, '/');
        if ($worker !== null && env('VERCEL') && $this->isLocalHost($worker)) {
            $worker = null;
        }
        $this->workerUrl = $worker;

        $key = trim((string) env('KURAMANIME_WORKER_KEY', ''));
        $this->workerKey = $key === '' ? null : $key;

        $base = trim((string) env('KURAMANIME_URL', ''));
        $this->configuredBase = $base === '' ? null : rtrim($base, '/');
    }

    public function enabled(): bool
    {
        return $this->workerUrl !== null;
    }

    protected function isLocalHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['127.0.0.1', 'localhost', '::1', '0.0.0.0'], true);
    }

    /**
     * Episode list in the shape AnimeStreamProvider expects.
     *
     * @param  array<string, mixed>  $anime
     * @return array<int, array<string, mixed>>
     */
    public function episodesFor(array $anime): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $series = $this->resolveSeries($anime);
        if ($series === null) {
            return [];
        }

        $range = $this->seriesEpisodeRange($series['id'], $series['slug']);
        if (empty($range['numbers'])) {
            return [];
        }

        $out = [];
        foreach ($range['numbers'] as $number) {
            $out[] = [
                'id' => 'km|'.$series['id'].'|'.$series['slug'].'|'.$number,
                'number' => (int) $number,
                'title' => '',
                'image' => null,
                'duration' => null,
            ];
        }

        return $out;
    }

    /**
     * Resolve a "km|{animeId}|{slug}|{number}" reference.
     *
     * @return array<string, mixed>|null
     */
    public function streamFor(string $reference): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $parts = explode('|', $reference);
        if (count($parts) < 4 || $parts[0] !== 'km') {
            return null;
        }

        $animeId = (int) $parts[1];
        $slug = (string) preg_replace('/[^a-z0-9\-]/i', '', $parts[2]);
        $number = (int) $parts[3];
        if ($animeId < 1 || $slug === '' || $number < 1) {
            return null;
        }

        return $this->resolveEpisode($animeId, $slug, $number);
    }

    /**
     * @param  array<string, mixed>  $anime
     * @return array<string, mixed>|null
     */
    public function streamForEpisode(array $anime, int $number): ?array
    {
        if (! $this->enabled() || $number < 1) {
            return null;
        }

        $series = $this->resolveSeries($anime);
        if ($series === null) {
            return null;
        }

        $range = $this->seriesEpisodeRange($series['id'], $series['slug']);
        if (empty($range['numbers'])) {
            return null;
        }

        $inList = in_array($number, $range['numbers'], true);
        $inRange = $number >= 1 && $number <= $range['max'];
        if (! $inList && ! $inRange) {
            return null;
        }

        return $this->resolveEpisode($series['id'], $series['slug'], $number);
    }

    /**
     * @param  array<string, mixed>  $anime
     * @return array{id: int, slug: string}|null
     */
    protected function resolveSeries(array $anime): ?array
    {
        $titles = $this->titleCandidates($anime, 4);
        if (empty($titles)) {
            return null;
        }

        $cacheKey = 'kuramanime_series_ref_v1_'.md5(implode('|', $titles));
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['id'], $cached['slug'])) {
            return ['id' => (int) $cached['id'], 'slug' => (string) $cached['slug']];
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
                            $score = max($score, 0.68);
                        }
                        $score = $this->adjustForSeason($candidate, $row['title'], $score);
                        if ($score > $bestScore) {
                            $bestScore = $score;
                            $best = ['id' => $row['id'], 'slug' => $row['slug']];
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
     * @return array<int, array{id: int, slug: string, title: string}>
     */
    protected function search(string $query): array
    {
        if (! $this->enabled() || trim($query) === '') {
            return [];
        }

        $cacheKey = 'kuramanime_search_v1_'.md5(strtolower(trim($query)));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $base = $this->baseUrl();
        $rows = [];

        $jsonBody = $this->fetch($base.'/quicksearch/get?search='.urlencode($query), [
            'Accept: application/json, text/javascript, */*; q=0.01',
            'X-Requested-With: XMLHttpRequest',
        ]);
        if ($jsonBody !== null) {
            $decoded = json_decode($jsonBody, true);
            $html = is_array($decoded) ? (string) ($decoded['html'] ?? '') : '';
            if ($html !== '') {
                $rows = $this->parseQuicksearchHtml($html);
            }
        }

        if (empty($rows)) {
            $html = $this->fetch($base.'/anime?search='.urlencode($query));
            if ($html !== null) {
                $rows = $this->parseCatalogueHtml($html);
            }
        }

        if (! empty($rows)) {
            Cache::put($cacheKey, $rows, 86400);
        }

        return $rows;
    }

    /**
     * @return array<int, array{id: int, slug: string, title: string}>
     */
    protected function parseQuicksearchHtml(string $html): array
    {
        $rows = [];
        if (preg_match_all(
            '~<a[^>]+href="https?://[^/]+/anime/(\d+)/([^"/#?]+)"[^>]*>(.*?)</a>~is',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $row) {
                $id = (int) $row[1];
                $slug = (string) $row[2];
                if ($id < 1 || $slug === '' || isset($rows[$id])) {
                    continue;
                }
                $title = '';
                if (preg_match('#<h5[^>]*class="[^"]*title[^"]*"[^>]*>(.*?)</h5>#is', $row[3], $t)) {
                    $title = trim(strip_tags(html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                }
                if ($title === '') {
                    $title = str_replace('-', ' ', $slug);
                }
                $rows[$id] = [
                    'id' => $id,
                    'slug' => $slug,
                    'title' => $title,
                ];
            }
        }

        return array_values($rows);
    }

    /**
     * @return array<int, array{id: int, slug: string, title: string}>
     */
    protected function parseCatalogueHtml(string $html): array
    {
        $rows = [];
        if (preg_match_all(
            '~<a[^>]+href="https?://[^/]+/anime/(\d+)/([^"/#?]+)"[^>]*>(.*?)</a>~is',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $row) {
                $id = (int) $row[1];
                $slug = (string) $row[2];
                if ($id < 1 || $slug === '' || isset($rows[$id])) {
                    continue;
                }
                $title = '';
                if (preg_match('#<h5[^>]*>(.*?)</h5>#is', $row[3], $t)) {
                    $title = trim(strip_tags(html_entity_decode($t[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                }
                if ($title === '') {
                    $title = trim(strip_tags(html_entity_decode($row[3], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                }
                if ($title === '') {
                    $title = str_replace('-', ' ', $slug);
                }
                $rows[$id] = [
                    'id' => $id,
                    'slug' => $slug,
                    'title' => $title,
                ];
            }
        }

        return array_values($rows);
    }

    /**
     * @return array{numbers: array<int, int>, max: int}
     */
    protected function seriesEpisodeRange(int $id, string $slug): array
    {
        $cacheKey = 'kuramanime_episodes_v1_'.$id.'_'.$slug;
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['numbers'], $cached['max'])) {
            return $cached;
        }

        $html = $this->fetch($this->baseUrl().'/anime/'.$id.'/'.rawurlencode($slug));
        if ($html === null) {
            return ['numbers' => [], 'max' => 0];
        }

        $numbers = [];
        if (preg_match_all(
            '#/anime/'.$id.'/'.preg_quote($slug, '#').'/episode/(\d+)#i',
            $html,
            $matches
        )) {
            foreach ($matches[1] as $n) {
                $num = (int) $n;
                if ($num >= 1) {
                    $numbers[$num] = true;
                }
            }
        }

        $max = empty($numbers) ? 0 : max(array_keys($numbers));
        $hasLatestBadge = false;
        if (preg_match('#Ep\s*(\d+)\s*\(Terbaru\)#i', $html, $latest)) {
            $max = max($max, (int) $latest[1]);
            $hasLatestBadge = true;
        }

        // Long-running and multi-cour series paginate #episodeLists (showing
        // page 1 + "Ep N (Terbaru)", or the latest page), while every
        // intermediate episode remains directly addressable at /episode/{n}.
        if ($max > 1 && ! empty($numbers) && ($hasLatestBadge ? count($numbers) < $max : min(array_keys($numbers)) > 1)) {
            $full = range(1, $max);
        } else {
            $full = array_keys($numbers);
            sort($full);
        }

        $result = ['numbers' => $full, 'max' => $max];
        if (! empty($full)) {
            Cache::put($cacheKey, $result, 21600);
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveEpisode(int $id, string $slug, int $number): ?array
    {
        $cacheKey = 'kuramanime_stream_v1_'.md5($id.'|'.$slug.'|'.$number);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $episodeUrl = $this->baseUrl().'/anime/'.$id.'/'.rawurlencode($slug).'/episode/'.$number;
        $data = $this->fetchWorker($episodeUrl);
        if (! is_array($data) || empty($data['ok']) || ($data['kind'] ?? '') !== 'mp4') {
            return null;
        }

        $sources = [];
        $seen = [];
        foreach (($data['sources'] ?? []) as $entry) {
            $url = trim((string) ($entry['url'] ?? ''));
            if ($url === '' || ! preg_match('#^https?://#i', $url) || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $sources[] = [
                'url' => $url,
                'quality' => (string) ($entry['quality'] ?? 'auto'),
                'isM3U8' => false,
            ];
        }

        if (empty($sources)) {
            return null;
        }

        $stream = [
            'sources' => $sources,
            'subtitles' => [],
            'kind' => 'mp4',
            'embed' => null,
            'referer' => (string) ($data['referer'] ?? $episodeUrl),
            'intro' => null,
            'outro' => null,
            'provider' => 'Kuramanime',
            'server' => 'Hardsub ID',
            'audio_fallback' => null,
            'hard_sub' => true,
            'hard_sub_lang' => 'id',
            'proxy_file' => true,
        ];

        Cache::put($cacheKey, $stream, 1800);

        return $stream;
    }

    protected function baseUrl(): string
    {
        if ($this->configuredBase !== null) {
            return $this->configuredBase;
        }

        return $this->resolveBaseUrl();
    }

    protected function resolveBaseUrl(): string
    {
        return Cache::remember('kuramanime_base_url_v1', 21600, function () {
            foreach (['https://kuramalink.app/', 'https://kuramalink.me/'] as $portal) {
                $ch = curl_init($portal);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_NOBODY => true,
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    CURLOPT_USERAGENT => self::UA,
                ]);
                curl_exec($ch);
                $effective = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($code >= 200 && $code < 400 && $effective !== '') {
                    $parts = parse_url($effective);
                    $host = strtolower((string) ($parts['host'] ?? ''));
                    if ($host !== '' && str_contains($host, 'kuramanime')) {
                        return ($parts['scheme'] ?? 'https').'://'.$host;
                    }
                }
            }

            return self::FALLBACK_BASE;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchWorker(string $episodeUrl): ?array
    {
        if ($this->workerUrl === null) {
            return null;
        }

        $payload = json_encode(['url' => $episodeUrl], JSON_UNESCAPED_SLASHES);
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($this->workerKey !== null) {
            $headers[] = 'x-api-key: '.$this->workerKey;
        }

        $ch = curl_init($this->workerUrl.'/stream');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 400) {
            Log::debug('Kuramanime worker request failed', [
                'url' => $episodeUrl,
                'status' => $code,
                'error' => $err,
            ]);

            return null;
        }

        $decoded = json_decode((string) $body, true);

        return is_array($decoded) ? $decoded : null;
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
            Log::debug('Kuramanime request failed', ['url' => $url, 'status' => $code]);

            return null;
        }

        return (string) $body;
    }
}
