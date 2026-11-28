<?php

namespace App\Services\Anime;

use App\Services\Anime\Concerns\MatchesTitles;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Nimegami: Indonesian hard-subtitled MP4 playback.
 *
 * Nimegami carries a large catalogue (~3,000+ titles) with direct Indonesian
 * hard-subtitled MP4s hosted on Berkasdrive (360p/480p/720p/1080p) and
 * playable via its /streaming/{ep}/{quality}/{mediaId}/ endpoint.
 *
 * Flow:
 *   /anime/?q={title}                         -> derive the series slug
 *   /{slug}/                                  -> streaming candidates per episode
 *   /{slug}/streaming/{ep}/{quality}/{id}/    -> <main data-src="...mp4...">
 *
 * Episode references look like: ng|{slug}|{episodeNumber}
 */
class NimegamiSource
{
    use MatchesTitles;

    protected const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    protected ?string $base;

    protected ?string $lastError = null;

    public function __construct()
    {
        $base = trim((string) env('NIMEGAMI_URL', 'https://nimegami.id'));
        $this->base = $base === '' ? null : rtrim($base, '/');
    }

    public function lastError(): ?string
    {
        return $this->lastError;
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
        foreach ($this->seriesEpisodes($slug) as $number => $candidates) {
            $out[] = [
                'id' => 'ng|'.$slug.'|'.$number,
                'number' => (int) $number,
                'title' => '',
                'image' => null,
                'duration' => null,
            ];
        }

        return $out;
    }

    /**
     * Resolve an "ng|{slug}|{number}" reference.
     *
     * @return array<string, mixed>|null
     */
    public function streamFor(string $reference): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $parts = explode('|', $reference);
        if (count($parts) < 3 || $parts[0] !== 'ng') {
            return null;
        }

        $slug = (string) preg_replace('/[^a-z0-9\-]/i', '', $parts[1]);
        $number = (int) $parts[2];
        if ($slug === '' || $number < 1) {
            return null;
        }

        $candidates = $this->seriesEpisodes($slug)[$number] ?? [];
        if (empty($candidates)) {
            return null;
        }

        return $this->resolveEpisode($slug, $number, $candidates);
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
        $candidates = $this->seriesEpisodes($slug)[$number] ?? [];
        if (empty($candidates)) {
            $this->lastError = $this->lastError ? 'p:'.$this->lastError : 'p:no_ep_'.$number;

            return null;
        }

        $this->lastError = null;
        $stream = $this->resolveEpisode($slug, $number, $candidates);
        if ($stream === null) {
            $this->lastError = $this->lastError ? 'e:'.$this->lastError : 'e:no_src';

            return null;
        }

        $this->lastError = 'ok';

        return $stream;
    }

    /**
     * Map a catalogue row to a Nimegami series slug.
     *
     * @param  array<string, mixed>  $anime
     */
    protected function resolveSlug(array $anime): ?string
    {
        $titles = $this->titleCandidates($anime, 4);
        if (empty($titles)) {
            return null;
        }

        $cacheKey = 'nimegami_slug_v2_'.md5(implode('|', $titles));
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

        $cacheKey = 'nimegami_search_'.md5(strtolower(trim($query)));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $html = $this->fetch($this->base.'/anime/?q='.urlencode($query));
        if ($html === null) {
            return [];
        }

        $rows = [];
        if (preg_match_all(
            '#<a[^>]*class="card-title"[^>]*href="/([a-z0-9\-]+)/"[^>]*>([^<]+)</a>#i',
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
                    'title' => trim(html_entity_decode((string) $row[2], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                ];
            }
        }

        // Fallback when markup omits card-title or reorders attributes.
        if (empty($rows) && preg_match_all(
            '#href="/([a-z0-9\-]+-sub-indo)/"#i',
            $html,
            $matches
        )) {
            foreach (array_unique($matches[1]) as $slug) {
                $name = preg_replace('/-sub-indo$/i', '', (string) $slug);
                $rows[$slug] = [
                    'slug' => (string) $slug,
                    'title' => str_replace('-', ' ', (string) $name),
                ];
            }
        }

        $result = array_values($rows);
        if (! empty($result)) {
            Cache::put($cacheKey, $result, 86400);
        }

        return $result;
    }

    /**
     * Streaming page candidates per episode, best quality first.
     *
     * @return array<int, array<int, array{path: string, quality: string, rank: int}>>
     */
    protected function seriesEpisodes(string $slug): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $cacheKey = 'nimegami_series_v4_'.$slug;
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $html = $this->fetch($this->base.'/'.rawurlencode($slug).'/');
        if ($html === null) {
            return [];
        }

        $byEpisode = [];

        // 1. Explicit /streaming/{ep}/{quality}/{mediaId}/ paths embedded in
        //    the Next.js RSC payload or rendered player links.
        $quotedSlug = preg_quote($slug, '#');
        if (preg_match_all(
            '#/' . $quotedSlug . '/streaming/(\d+)/(\d+p)/([a-z0-9\-]+)/(\?player=[a-z0-9_]+)?#i',
            $html,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $row) {
                $ep = (int) $row[1];
                if ($ep < 1) {
                    continue;
                }
                $quality = strtolower((string) $row[2]);
                $query = (string) ($row[4] ?? '');
                $path = '/' . $slug . '/streaming/' . $ep . '/' . $quality . '/' . $row[3] . '/' . $query;
                $rank = $this->qualityRank($quality) * 10 + ($query === '' ? 5 : 2);
                $byEpisode[$ep][$path] = [
                    'path' => $path,
                    'quality' => $quality,
                    'rank' => $rank,
                ];
            }
        }

        // 2. Older migrated titles expose "Berkasdrive" or "SR Utama" links in
        //    #download_episode_{n} instead of pre-listing /streaming/ in RSC.
        if (preg_match_all(
            '#id="download_episode_(\d+)"(.*?)(?=id="download_episode_\d+"|</section>|$)#is',
            $html,
            $epBlocks,
            PREG_SET_ORDER
        )) {
            foreach ($epBlocks as $block) {
                $ep = (int) $block[1];
                if ($ep < 1) {
                    continue;
                }
                if (! preg_match_all(
                    '#<div class="quality-label"><strong>(\d+p)</strong>.*?<div class="download-mirrors">(.*?)</div>#is',
                    $block[2],
                    $qRows,
                    PREG_SET_ORDER
                )) {
                    continue;
                }
                foreach ($qRows as $qRow) {
                    $quality = strtolower((string) $qRow[1]);
                    if (! preg_match_all(
                        '#<a[^>]+href="(/' . $quotedSlug . '/download/(?:\d+/\d+p/)?([a-z0-9\-]+)/)"[^>]*>(.*?)</a>#is',
                        $qRow[2],
                        $links,
                        PREG_SET_ORDER
                    )) {
                        continue;
                    }
                    foreach ($links as $link) {
                        $label = strtolower(strip_tags((string) $link[3]) . ' ' . $link[0]);
                        $isPrimary = str_contains($label, 'berkasdrive');
                        $isWorker = str_contains($label, 'sr utama');
                        if (! $isPrimary && ! $isWorker) {
                            continue;
                        }
                        $query = $isWorker ? '?player=worker' : '';
                        $path = '/' . $slug . '/streaming/' . $ep . '/' . $quality . '/' . $link[2] . '/' . $query;
                        $rank = $this->qualityRank($quality) * 10 + ($isPrimary ? 4 : 1);
                        if (! isset($byEpisode[$ep][$path])) {
                            $byEpisode[$ep][$path] = [
                                'path' => $path,
                                'quality' => $quality,
                                'rank' => $rank,
                            ];
                        }
                    }
                }
            }
        }

        // 3. Archive/Komikcast-sourced titles store worker-proxied playdesu URLs
        //    inside the Next.js RSC server objects instead of /streaming/ routes.
        $unescaped = str_replace(['\\"', '\\/'], ['"', '/'], $html);
        if (preg_match_all('#\{[^{}]*"playUrl":"https?://[^"]+"[^{}]*\}#i', $unescaped, $rscObjects)) {
            foreach ($rscObjects[0] as $json) {
                $item = json_decode($json, true);
                if (! is_array($item) || ($item['kind'] ?? '') !== 'episode') {
                    continue;
                }
                $ep = (int) ($item['episode'] ?? 0);
                if ($ep < 1) {
                    continue;
                }
                $rawQuality = strtolower(trim((string) ($item['quality'] ?? 'auto')));
                $quality = preg_match('/^\d{3,4}p$/', $rawQuality) ? $rawQuality : 'auto';
                foreach (['playUrl', 'url'] as $field) {
                    $candidateUrl = trim((string) ($item[$field] ?? ''));
                    if (! $this->isSupportedPlayUrl($candidateUrl)) {
                        continue;
                    }
                    $rank = $this->qualityRank($quality) * 10 + (str_contains($candidateUrl, 'berkasdrive.com') ? 2 : 1);
                    if (! isset($byEpisode[$ep][$candidateUrl])) {
                        $byEpisode[$ep][$candidateUrl] = [
                            'path' => $candidateUrl,
                            'quality' => $quality,
                            'rank' => $rank,
                        ];
                    }
                    // If the worker-wrapped desustream URL returns 502 from a
                    // cloud region, try fetching desustream directly as a fallback.
                    if (preg_match('#^https?://[^/]+/(https?://desustream\.[^/]+/.+)$#i', $candidateUrl, $directMatch)) {
                        $directUrl = $directMatch[1];
                        if (! isset($byEpisode[$ep][$directUrl])) {
                            $byEpisode[$ep][$directUrl] = [
                                'path' => $directUrl,
                                'quality' => $quality,
                                'rank' => $this->qualityRank($quality) * 10,
                            ];
                        }
                    }
                }
            }
        }

        $episodes = [];
        foreach ($byEpisode as $ep => $candidates) {
            $list = array_values($candidates);
            usort($list, fn ($a, $b) => $b['rank'] <=> $a['rank']);
            $episodes[$ep] = $list;
        }
        ksort($episodes);

        if (! empty($episodes)) {
            Cache::put($cacheKey, $episodes, 21600);
        }

        return $episodes;
    }

    protected function isSupportedPlayUrl(string $url): bool
    {
        if (! preg_match('#^https?://#i', $url)) {
            return false;
        }

        return str_contains($url, 'berkasdrive.com/')
            || str_contains($url, '.workers.dev/');
    }

    /**
     * @param  array<int, array{path: string, quality: string, rank: int}>  $candidates
     * @return array<string, mixed>|null
     */
    protected function resolveEpisode(string $slug, int $number, array $candidates): ?array
    {
        $cacheKey = 'nimegami_stream_v4_'.md5($slug.'|'.$number);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $sources = [];
        $seen = [];
        foreach (array_slice($candidates, 0, 6) as $candidate) {
            $targetUrl = preg_match('#^https?://#i', $candidate['path'])
                ? $candidate['path']
                : $this->base.$candidate['path'];
            $html = $this->fetch($targetUrl, [
                'Referer: '.$this->base.'/'.$slug.'/',
            ]);
            if ($html === null) {
                continue;
            }

            $src = $this->extractDirectVideoSrc($html);
            if ($src === null || isset($seen[$src])) {
                continue;
            }

            $seen[$src] = true;
            $sources[] = [
                'url' => $src,
                'quality' => $candidate['quality'] ?? 'auto',
                'isM3U8' => false,
            ];
            if (count($sources) >= 2) {
                break;
            }
        }

        if (empty($sources)) {
            return null;
        }

        $stream = [
            'sources' => $sources,
            'subtitles' => [],
            'kind' => 'mp4',
            'embed' => null,
            'referer' => $this->base.'/',
            'intro' => null,
            'outro' => null,
            'provider' => 'Nimegami',
            'server' => 'Hardsub ID',
            'audio_fallback' => null,
            'hard_sub' => true,
            'hard_sub_lang' => 'id',
            'proxy_file' => true,
        ];

        Cache::put($cacheKey, $stream, 3600);

        return $stream;
    }

    protected function extractDirectVideoSrc(string $html): ?string
    {
        // Reject embedded third-party iframes on /streaming/ pages: Burned-in
        // mode promises a direct video file we control inside our own player.
        if (preg_match('#\bdata-embedded="1"#i', $html)) {
            return null;
        }

        if (preg_match('#\bdata-src="([^"]+)"#i', $html, $m)) {
            $src = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (preg_match('#^https?://#i', $src)) {
                return $src;
            }
        }

        // Worker-proxied playdesu wrappers render a direct <video id="video" src="...">
        // pointing at a GoogleVideo / MP4 stream.
        if (preg_match('#<(?:video|source)[^>]+src="([^"]+)"#i', $html, $m)) {
            $src = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $lower = strtolower($src);
            if (preg_match('#^https?://#i', $src)
                && (str_contains($lower, '.mp4')
                    || str_contains($lower, 'mime=video/mp4')
                    || str_contains($lower, 'videoplayback'))) {
                if (preg_match('#^https://[^/]*googlevideo\.com/#i', $src)) {
                    return 'https://workers-anime.berkasdrive.com/'.$src;
                }

                return $src;
            }
        }

        return null;
    }

    protected function qualityRank(string $quality): int
    {
        return match (strtolower($quality)) {
            '1080p' => 4,
            '720p' => 3,
            '480p' => 2,
            '360p' => 1,
            default => 0,
        };
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
            Log::debug('Nimegami request failed', ['url' => $url, 'status' => $code, 'errno' => $errno, 'attempt' => $attempt]);

            if ($attempt === 0 && in_array($code, [502, 503, 504], true)) {
                usleep(200_000);
                continue;
            }

            break;
        }

        return null;
    }
}
