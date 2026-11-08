<?php

namespace App\Services\Anime;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Streaming resolver backed by a self-hosted Anivexa API instance.
 *
 * Anivexa aggregates many streaming providers keyed directly by AniList ID
 * (the same identity layer as our catalogue), e.g.:
 *   GET {base}/episodes/{anilistId}
 *   GET {base}/watch/{provider}/{anilistId}/sub/{provider}-{episode}
 *
 * Watch responses contain direct HLS streams plus per-language VTT
 * subtitle tracks (AnimeDunya even ships an Indonesian track), so the
 * watch page plays everything in our own player. No embeds, ever.
 *
 * Run it locally next to this app:
 *   git clone https://github.com/walterwhite-69/Anivexa-API
 *   cd Anivexa-API && npm install && cp .env.example .env && node server.js
 *   -> http://localhost:4000  (override with ANIME_API_URL)
 */
class AnimeStreamProvider
{
    protected string $base;

    /** Additional resolver bases (comma-separated ANIME_API_URL), tried when the primary is unreachable. */
    protected array $fallbacks = [];

    /** Shared secret for keyed resolver instances (ANIME_API_KEY), sent as x-api-key. */
    protected ?string $apiKey = null;

    /** Provider priority: proven HLS + subtitle sources first. */
    protected array $priority = [
        'anikoto', 'aniwaves', 'animedunya', 'anizone', 'anipm', 'animenosub',
        'animeonsen', 'reanime', 'animegg', 'senshi', 'mkissa', 'kaa',
    ];

    public function __construct()
    {
        $urls = collect(explode(',', (string) env('ANIME_API_URL', env('CONSUMET_API_URL', 'http://127.0.0.1:4000'))))
            ->map(fn ($u) => rtrim(trim($u), '/'))
            ->filter()
            ->unique()
            ->values();
        $this->base = $urls->first() ?: 'http://127.0.0.1:4000';
        $this->fallbacks = $urls->slice(1)->all();
        $key = env('ANIME_API_KEY');
        $this->apiKey = is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * All configured resolver bases, primary first.
     *
     * @return array<int, string>
     */
    protected function bases(): array
    {
        return [$this->base, ...$this->fallbacks];
    }
    protected function getJson(string $url, int $timeout = 25): ?array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
$headers = ['Accept: application/json'];
        if ($this->apiKey !== null) {
            $headers[] = 'x-api-key: ' . $this->apiKey;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, 'BiblioManga/1.0.0 (https://github.com/Bimakanz/BiblioCore)');
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $code >= 400) {
            Log::warning("Anivexa API failed ({$code}): {$url}");
            return null;
        }
        $data = json_decode($res, true);
        return is_array($data) && !isset($data['error']) ? $data : null;
    }

    /**
     * Fetch episode lists per provider in parallel waves (curl_multi).
     * Returns a map of provider name => raw provider block. Wave 1 is the
     * five highest-priority providers; wave 2 only runs when wave 1
     * produced nothing. The aggregate /episodes/{id} endpoint is NOT used:
     * for long-running titles it returns ~11 MB and needs >30 s (33.8 s
     * measured for One Piece), which always exceeded the timeout and
     * silently produced an empty episode list.
     */
    protected function fetchProviderEpisodes(int $anilistId): array
    {
        // Narrow first wave: the two proven-fastest HLS providers. A wave
        // waits for its slowest member, so keeping wave 1 tiny keeps the
        // common case fast; wider waves only run when needed.
        $waves = [
            array_slice($this->priority, 0, 2),
            array_slice($this->priority, 2, 3),
            array_slice($this->priority, 5),
        ];
        $blocks = [];
        foreach ($waves as $wave) {
            $results = [];
            foreach ($this->bases() as $base) {
                $urls = [];
                foreach ($wave as $name) {
                    $urls[$name] = $base . "/episodes/{$name}/{$anilistId}";
                }
                $results = $this->getJsonMulti($urls, 8000);
                if (!empty($results)) {
                    break;
                }
            }
            foreach ($results as $name => $data) {
                $block = $data[$name] ?? null;
                if (is_array($block)) {
                    $blocks[$name] = $block;
                }
            }
            if (!empty($blocks)) {
                break;
            }
        }
        return $blocks;
    }

    /** Parallel version of getJson(): map of key => decoded JSON array. */
    protected function getJsonMulti(array $urls, int $timeoutMs = 8000): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($urls as $key => $url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 3000);
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeoutMs);
    $headers = ['Accept: application/json'];
        if ($this->apiKey !== null) {
            $headers[] = 'x-api-key: ' . $this->apiKey;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_USERAGENT, 'BiblioManga/1.0.0 (https://github.com/Bimakanz/BiblioCore)');
            curl_multi_add_handle($multi, $ch);
            $handles[$key] = $ch;
        }
        $running = null;
        $completed = [];
        $early = false;
        $started = microtime(true);
        $deadline = $started + ($timeoutMs / 1000);

        do {
            $status = curl_multi_exec($multi, $running);

            // Harvest finished transfers. As soon as one of them yields a
            // usable episode list we stop waiting for the slow siblings:
            // the wave must not be held hostage by the slowest provider.
            while ($info = curl_multi_info_read($multi)) {
                $handle = $info['handle'];
                $id = spl_object_id($handle);
                $completed[$id] = $info['result'];
                if (! $early && $info['result'] === CURLE_OK) {
                    $data = json_decode((string) curl_multi_getcontent($handle), true);
                    if (is_array($data) && ! isset($data['error'])) {
                        foreach ($data as $block) {
                            if (is_array($block) && ! empty($block['episodes']['sub'])) {
                                $early = true;
                                break;
                            }
                        }
                    }
                }
            }

            if ($early) {
                break;
            }
            if ($running && microtime(true) < $deadline) {
                curl_multi_select($multi, 0.25);
            }
        } while ($running && microtime(true) < $deadline && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $key => $ch) {
            $finished = isset($completed[spl_object_id($ch)]);
            if (! $finished) {
                // Dropped by the deadline/early exit: never read partial JSON.
                curl_multi_remove_handle($multi, $ch);
                curl_close($ch);
                continue;
            }
            $res = curl_multi_getcontent($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
            if ($res === false || $res === null || $code >= 400) {
                continue;
            }
            $data = json_decode((string) $res, true);
            if (is_array($data) && !isset($data['error'])) {
                $results[$key] = $data;
            }
        }
        curl_multi_close($multi);
        return $results;
    }

    /**
     * Normalized per-provider episode lists for an AniList id.
     * Returns ['provider' => primaryName, 'providers' => [names...],
     *          'episodes' => [ ['id' => watchPath, 'number', 'title', 'image', 'duration'] ]]
     * Empty results are NOT cached: Anivexa refreshes providers in the
     * background, so a later call may succeed where the first was partial.
     */
    public function getEpisodes(int $anilistId): array
    {
        $empty = ['provider' => null, 'providers' => [], 'episodes' => []];
        if ($anilistId <= 0) {
            return $empty;
        }

        $cached = Cache::get("anivexa_episodes_{$anilistId}");
        if (is_array($cached) && !empty($cached['episodes'])) {
            return $cached;
        }

        $responses = $this->fetchProviderEpisodes($anilistId);
        if (empty($responses)) {
            return $empty;
        }

        $byProvider = [];
        foreach ($this->priority as $name) {
            $block = $responses[$name] ?? null;
            $list = $block['episodes']['sub'] ?? $block['episodes']['dub'] ?? [];
            if (!is_array($list) || empty($list)) {
                continue;
            }
            $normalized = [];
            foreach ($list as $ep) {
                if (empty($ep['id'])) {
                    continue;
                }
                $normalized[] = [
                    'id' => (string) $ep['id'],
                    'number' => (int) ($ep['number'] ?? count($normalized) + 1),
                    'title' => (string) ($ep['title'] ?? ''),
                    'image' => $ep['image'] ?? null,
                    'duration' => $ep['duration'] ?? null,
                ];
            }
            if (!empty($normalized)) {
                usort($normalized, fn ($a, $b) => $a['number'] <=> $b['number']);
                $byProvider[$name] = $normalized;
            }
        }

        if (empty($byProvider)) {
            return $empty;
        }

        Cache::put("anivexa_episodes_by_provider_{$anilistId}", $byProvider, 21600);

        $primary = array_key_first($byProvider);
        $result = [
            'provider' => $primary,
            'providers' => array_keys($byProvider),
            'episodes' => $byProvider[$primary],
        ];
        Cache::put("anivexa_episodes_{$anilistId}", $result, 21600);
        return $result;
    }

    /** Watch path anatomy: watch/{provider}/{anilistId}/{audio}/{provider}-{number} */
    protected function parseWatchPath(string $watchPath): ?array
    {
        if (preg_match('#^watch/([a-z0-9]+)/(\d+)/(sub|dub)/[a-z0-9]+-(\d+(?:\.\d+)?)$#i', trim($watchPath, '/'), $m)) {
            return ['provider' => strtolower($m[1]), 'anilistId' => (int) $m[2], 'audio' => strtolower($m[3]), 'number' => (float) $m[4]];
        }
        return null;
    }

    /** Find the watch path of the same episode number on another provider. */
    protected function alternativePath(int $anilistId, float $number, string $provider): ?string
    {
        $byProvider = Cache::get("anivexa_episodes_by_provider_{$anilistId}");
        if (!is_array($byProvider)) {
            $this->getEpisodes($anilistId);
            $byProvider = Cache::get("anivexa_episodes_by_provider_{$anilistId}");
        }
        foreach (($byProvider[$provider] ?? []) as $ep) {
            if ((float) $ep['number'] === $number) {
                return $ep['id'];
            }
        }
        return null;
    }

    /**
     * Resolve playback for a watch path: direct HLS + subtitle tracks.
     * When $providerOverride names another provider, the same episode
     * number is streamed from that provider instead (player failover).
     * A failed primary path also fails over across up to 2 other providers.
     */
    public function getStream(string $watchPath, ?string $providerOverride = null): ?array
    {
        $parsed = $this->parseWatchPath($watchPath);

        if ($providerOverride && $parsed && $providerOverride !== $parsed['provider']) {
            $alt = $this->alternativePath($parsed['anilistId'], $parsed['number'], $providerOverride);
            if ($alt) {
                $watchPath = $alt;
                $parsed = $this->parseWatchPath($watchPath);
            }
        }

        $cacheKey = 'anivexa_stream_' . md5($watchPath);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $stream = $this->fetchStream($watchPath);

        // Failover: try up to two other providers for the same episode.
        if (!$stream && $parsed) {
            foreach ($this->priority as $name) {
                if ($name === $parsed['provider']) {
                    continue;
                }
                $alt = $this->alternativePath($parsed['anilistId'], $parsed['number'], $name);
                if (!$alt) {
                    continue;
                }
                $stream = $this->fetchStream($alt);
                if ($stream) {
                    $stream['provider'] = $name;
                    break;
                }
            }
        }

        if ($stream) {
            Cache::put($cacheKey, $stream, 3600);
        }
        return $stream;
    }

    protected function fetchStream(string $watchPath): ?array
    {
        $data = null;
        foreach ($this->bases() as $base) {
            $data = $this->getJson($base . '/' . ltrim($watchPath, '/'), 30);
            if ($data) {
                break;
            }
        }
        if (!$data || empty($data['streams']) || !is_array($data['streams'])) {
            return null;
        }

        $chosen = null;
        foreach ($data['streams'] as $s) {
            if (empty($s['url'])) {
                continue;
            }
            $isHls = ($s['type'] ?? '') === 'hls' || str_contains($s['url'], '.m3u8');
            if ($isHls && ($s['isActive'] ?? true)) {
                $chosen = $s;
                break;
            }
            if ($isHls && $chosen === null) {
                $chosen = $s;
            }
        }
        if (!$chosen) {
            return null;
        }

        $subtitles = [];
        foreach (($chosen['subtitles'] ?? []) as $sub) {
            if (empty($sub['url'])) {
                continue;
            }
            $lang = strtolower((string) ($sub['srclang'] ?? $sub['label'] ?? 'en'));
            $subtitles[] = [
                'lang' => $lang,
                'label' => (string) ($sub['label'] ?? strtoupper($lang)),
                'url' => $sub['url'],
            ];
        }

        $parsed = $this->parseWatchPath($watchPath);

        return [
            'sources' => [[
                'url' => $chosen['url'],
                'quality' => 'auto',
                'isM3U8' => true,
            ]],
            'subtitles' => $subtitles,
            'referer' => $chosen['referer'] ?? null,
            'intro' => null,
            'outro' => null,
            'provider' => $parsed['provider'] ?? null,
            'server' => $chosen['server'] ?? null,
        ];
    }

    /** Provider names offered in the player as failover choices. */
    public function servers(): array
    {
        return $this->priority;
    }
}

