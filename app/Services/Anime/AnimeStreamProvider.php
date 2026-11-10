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

    /** Optional secondary sources (HiAnime, Consumet, ARM). */
    protected AnimeExtraSources $extra;

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
        $this->extra = new AnimeExtraSources();
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
     * two proven-fastest providers; the wider waves keep running while the
     * best list found so far is still shorter than $expected (episodes that
     * already aired), so a slow provider holding a complete catalogue is
     * never skipped just because a fast one returned a single episode.
     * The aggregate /episodes/{id} endpoint is NOT used: for long-running
     * titles it returns ~11 MB and needs >30 s (33.8 s measured for One
     * Piece), which always exceeded the timeout and silently produced an
     * empty episode list.
     *
     * @param  int|null  $expected  episodes that already aired, when known
     */
    protected function fetchProviderEpisodes(int $anilistId, ?int $expected = null): array
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
            if ($this->episodeBlocksSatisfied($blocks, $expected)) {
                break;
            }
            if (empty($wave)) {
                continue;
            }
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
        }
        return $blocks;
    }

    /**
     * Whether the blocks collected so far are good enough to stop asking.
     * With no expectation, any non-empty result is enough (legacy behaviour);
     * otherwise the longest list must cover the episodes that already aired.
     */
    protected function episodeBlocksSatisfied(array $blocks, ?int $expected): bool
    {
        if (empty($blocks)) {
            return false;
        }
        if ($expected === null || $expected <= 0) {
            return true;
        }

        $longest = 0;
        foreach ($blocks as $block) {
            $list = $block['episodes']['sub'] ?? $block['episodes']['dub'] ?? [];
            if (is_array($list)) {
                $longest = max($longest, count($list));
            }
        }

        // Providers routinely lag one or two episodes behind the broadcast
        // (raw uploads, numbering quirks). Chasing that last gap would make
        // every request pay for all three waves, so treat a list that is
        // within two episodes as complete.
        return $longest >= max(1, $expected - 2);
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
     * The episode list is the union of every provider that answered: the
     * earliest provider in the priority list wins each episode number, and
     * later providers fill the gaps (a fast provider often lags behind on
     * freshly airing shows while another already has the full catalogue).
     * Empty results are NOT cached: Anivexa refreshes providers in the
     * background, so a later call may succeed where the first was partial.
     *
     * @param  int|null  $expected  episodes that already aired, when known
     * @param  string  $audio  'sub' or 'dub'
     */
    public function getEpisodes(int $anilistId, ?int $expected = null, string $audio = 'sub', ?array $anime = null): array
    {
        $audio = $audio === 'dub' ? 'dub' : 'sub';
        $empty = ['provider' => null, 'providers' => [], 'episodes' => [], 'audio' => $audio, 'audioAvailable' => ['sub' => false, 'dub' => false]];
        if ($anilistId <= 0) {
            return $empty;
        }

        $cacheKey = "anivexa_episodes_{$anilistId}_{$audio}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && !empty($cached['episodes'])
            && ($expected === null || $expected <= 0 || count($cached['episodes']) >= $expected)) {
            return $cached;
        }

        $responses = $this->fetchProviderEpisodes($anilistId, $expected);

        // Audio availability comes from the same response: providers expose
        // separate `episodes.sub` / `episodes.dub` arrays, so a title can
        // have 12 sub episodes and 3 dub episodes (or none at all).
        $audioAvailable = $this->audioAvailabilityFrom($responses);
        if (!empty($responses)) {
            Cache::put("anivexa_audio_{$anilistId}", $audioAvailable, 21600);
        } else {
            $audioAvailable = $this->audioAvailability($anilistId);
        }

        if (empty($responses)) {
            $audioAvailable = $this->audioAvailability($anilistId);
        }

        $byProvider = [];
        $byNumber = [];
        foreach ($this->providerOrder(array_keys($responses)) as $name) {
            $block = $responses[$name] ?? null;
            $list = $block['episodes'][$audio] ?? [];
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
            if (empty($normalized)) {
                continue;
            }
            usort($normalized, fn ($a, $b) => $a['number'] <=> $b['number']);
            $byProvider[$name] = $normalized;
            foreach ($normalized as $ep) {
                if (!isset($byNumber[$ep['number']])) {
                    $byNumber[$ep['number']] = $ep;
                }
            }
        }

        // Secondary sources: only pay for them when the primary list is
        // short or the requested track is missing entirely (fresh cours and
        // dub tracks are exactly where Anivexa lags).
        $needsExtra = empty($byNumber)
            || ($expected !== null && $expected > 0 && count($byNumber) < max(1, $expected - 2))
            || ($audio === 'dub' && empty($audioAvailable['dub']));
        if ($needsExtra) {
            foreach (['hianime', 'consumet'] as $source) {
                $list = $source === 'hianime'
                    ? $this->extra->hianimeEpisodes($anime ?? [], $audio)
                    : $this->extra->consumetEpisodes($anime ?? [], $audio);
                foreach ($list as $ep) {
                    if (! isset($byNumber[$ep['number']])) {
                        $byNumber[$ep['number']] = $ep;
                    }
                }
                if (! empty($list)) {
                    $audioAvailable[$audio] = true;
                }
            }
        }

        if (empty($byProvider) && empty($byNumber)) {
            return is_array($cached) && !empty($cached['episodes'])
                ? $cached
                : array_merge($empty, ['audioAvailable' => $audioAvailable]);
        }

        if (! empty($byProvider)) {
            Cache::put("anivexa_episodes_by_provider_{$anilistId}_{$audio}", $byProvider, 21600);
        }
        Cache::put("anivexa_audio_{$anilistId}", $audioAvailable, 21600);

        ksort($byNumber);
        $primary = array_key_first($byProvider);
        $result = [
            'provider' => $primary,
            'providers' => array_keys($byProvider),
            'episodes' => array_values($byNumber),
            'audio' => $audio,
            'audioAvailable' => $audioAvailable,
        ];
        Cache::put($cacheKey, $result, 21600);
        return $result;
    }

    /** Which audio tracks the resolver reports for a title. */
    protected function audioAvailabilityFrom(array $responses): array
    {
        $available = ['sub' => false, 'dub' => false];
        foreach ($responses as $block) {
            foreach (['sub', 'dub'] as $audio) {
                if (!empty($block['episodes'][$audio]) && is_array($block['episodes'][$audio])) {
                    $available[$audio] = true;
                }
            }
        }
        return $available;
    }

    public function audioAvailability(int $anilistId): array
    {
        $cached = Cache::get("anivexa_audio_{$anilistId}");
        if (is_array($cached)) {
            return [
                'sub' => !empty($cached['sub']),
                'dub' => !empty($cached['dub']),
            ];
        }
        return ['sub' => false, 'dub' => false];
    }

    /**
     * Cheap dub probe for the server-rendered watch page: two priority
     * providers, short timeout. Unknown/unreachable is treated as "no dub"
     * on purpose — the toggle must never appear just to raise hopes.
     */
    public function dubAvailable(int $anilistId): bool
    {
        if ($anilistId <= 0) {
            return false;
        }

        $cached = Cache::get("anivexa_audio_{$anilistId}");
        if (is_array($cached)) {
            return ! empty($cached['dub']);
        }

        $urls = [];
        foreach ($this->bases() as $base) {
            foreach (array_slice($this->priority, 0, 2) as $name) {
                $urls[$name] = $base . "/episodes/{$name}/{$anilistId}";
            }
            break;
        }

        $results = $urls ? $this->getJsonMulti($urls, 3000) : [];
        $available = $this->audioAvailabilityFrom($results);
        if (! empty($results)) {
            Cache::put("anivexa_audio_{$anilistId}", $available, 21600);
        }

        return ! empty($available['dub']);
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
    protected function alternativePath(int $anilistId, float $number, string $provider, string $audio = 'sub'): ?string
    {
        $audio = $audio === 'dub' ? 'dub' : 'sub';
        $cacheKey = "anivexa_episodes_by_provider_{$anilistId}_{$audio}";
        $byProvider = Cache::get($cacheKey);
        if (!is_array($byProvider)) {
            $this->getEpisodes($anilistId, null, $audio);
            $byProvider = Cache::get($cacheKey);
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
    /** Route an episode reference that came from a secondary source. */
    protected function extraStream(string $reference): ?array
    {
        if (! str_contains($reference, '|')) {
            return null;
        }
        if (! str_starts_with($reference, 'hn|') && ! str_starts_with($reference, 'cs|')) {
            return null;
        }

        $cacheKey = 'anime_extra_stream_' . md5($reference);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $stream = str_starts_with($reference, 'hn|')
            ? $this->extra->hianimeStream($reference, 'sub')
            : $this->extra->consumetStream($reference);

        if ($stream) {
            Cache::put($cacheKey, $stream, 3600);
        }

        return $stream;
    }

    public function getStream(string $watchPath, ?string $providerOverride = null): ?array
    {
        // Episodes that came from a secondary source route back to it.
        $extraStream = $this->extraStream($watchPath);
        if ($extraStream !== null) {
            return $extraStream;
        }

        $parsed = $this->parseWatchPath($watchPath);
        $audio = $parsed['audio'] ?? 'sub';

        if ($providerOverride && $parsed && $providerOverride !== $parsed['provider']) {
            $alt = $this->alternativePath($parsed['anilistId'], $parsed['number'], $providerOverride, $audio);
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

        // Failover: try every provider that actually lists this episode, in
        // priority order (providers the resolver added beyond our list count
        // too, otherwise a working source can be skipped).
        if (!$stream && $parsed) {
            $byProvider = Cache::get("anivexa_episodes_by_provider_{$parsed['anilistId']}_{$audio}");
            $order = is_array($byProvider) && !empty($byProvider)
                ? $this->providerOrder(array_keys($byProvider))
                : $this->priority;
            foreach ($order as $name) {
                if ($name === $parsed['provider']) {
                    continue;
                }
                $alt = $this->alternativePath($parsed['anilistId'], $parsed['number'], $name, $audio);
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

    /**
     * Normalise the stream list of a watch response. Anivexa historically
     * answered with `streams` entries carrying `type: hls`; newer builds and
     * Miruro-style resolvers answer with consumet-shaped `sources` (`file`,
     * `isM3U8`), sometimes wrapped in `data`. All of them are accepted so a
     * resolver upgrade never turns into "every server failed".
     */
    protected function extractStreams(array $data): array
    {
        $out = [];
        foreach (['streams', 'sources'] as $key) {
            if (!empty($data[$key]) && is_array($data[$key])) {
                foreach ($data[$key] as $entry) {
                    if (is_array($entry)) {
                        $out[] = $entry;
                    }
                }
            }
        }
        if (empty($out) && isset($data['data']) && is_array($data['data'])) {
            return $this->extractStreams($data['data']);
        }
        return $out;
    }

    protected function streamUrl(array $stream): string
    {
        foreach (['url', 'file', 'link', 'src'] as $key) {
            if (!empty($stream[$key]) && is_string($stream[$key])) {
                return $stream[$key];
            }
        }
        return '';
    }

    protected function isHlsStream(array $stream, string $url): bool
    {
        $type = strtolower((string) ($stream['type'] ?? ''));
        return $type === 'hls' || $type === 'm3u8'
            || str_contains($url, '.m3u8')
            || !empty($stream['isM3U8']);
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
        if (!$data) {
            return null;
        }

        $streams = $this->extractStreams($data);
        if (empty($streams)) {
            // Keep a breadcrumb: an unexpected payload shape used to look
            // exactly like "provider has no stream" in the player.
            Log::debug('anivexa watch payload without streams', [
                'path' => $watchPath,
                'keys' => array_keys($data),
            ]);
            return null;
        }

        $chosen = null;
        foreach ($streams as $s) {
            $url = $this->streamUrl($s);
            if ($url === '') {
                continue;
            }
            $isHls = $this->isHlsStream($s, $url);
            if ($isHls && ($s['isActive'] ?? $s['active'] ?? true)) {
                $chosen = $s;
                $chosen['url'] = $url;
                break;
            }
            if ($isHls && $chosen === null) {
                $chosen = $s;
                $chosen['url'] = $url;
            }
        }
        if (!$chosen) {
            return null;
        }

        $subtitles = [];
        $tracks = $chosen['subtitles'] ?? $chosen['tracks'] ?? $data['subtitles'] ?? $data['tracks'] ?? [];
        foreach ((is_array($tracks) ? $tracks : []) as $sub) {
            $subUrl = (string) ($sub['url'] ?? $sub['file'] ?? $sub['src'] ?? '');
            if ($subUrl === '') {
                continue;
            }
            $lang = strtolower((string) ($sub['srclang'] ?? $sub['lang'] ?? $sub['label'] ?? 'en'));
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

    /**
     * Priority-first ordering of the provider names a resolver actually
     * returned. The list above used to be the ONLY providers ever read, so a
     * resolver that ships a newer provider (allmanga, anineko, animetsu, ...)
     * had those episodes fetched and then thrown away, leaving titles
     * unplayable even though a working source was sitting in the response.
     */
    protected function providerOrder(array $names): array
    {
        $names = array_values(array_unique(array_map(fn ($n) => strtolower((string) $n), $names)));
        $ordered = array_values(array_filter($this->priority, fn ($n) => in_array($n, $names, true)));
        foreach ($names as $name) {
            if ($name !== '' && !in_array($name, $ordered, true)) {
                $ordered[] = $name;
            }
        }
        return $ordered;
    }

    /** Provider names offered in the player as failover choices. */
    public function servers(): array
    {
        return $this->priority;
    }
}

