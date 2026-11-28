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

    /** Provider priority: verified unencrypted direct HLS (.m3u8) first. */
    protected array $priority = [
        'anikoto', 'aniwaves', 'animedunya', 'anizone', 'anipm', 'animenosub',
        'animeonsen', 'animegg', 'senshi', 'mkissa', 'kaa', 'reanime',
    ];

    /** Self-hosted Anivexa resolver instance. */
    protected const DEFAULT_BASES = [
        'https://api-eps-nine.vercel.app',
    ];

    public function __construct(?AnimeExtraSources $extra = null)
    {
        $configured = collect(explode(',', (string) env('ANIME_API_URL', env('CONSUMET_API_URL', ''))))
            ->map(fn ($u) => rtrim(trim($u), '/'))
            ->filter()
            ->reject(fn ($u) => $this->isLocalHost($u))
            ->unique()
            ->values();

        // A fresh Vercel project has no ANIME_API_URL. Falling back to
        // 127.0.0.1:4000 made every episode list silently empty there.
        $urls = $configured->isNotEmpty() ? $configured : collect(self::DEFAULT_BASES);

        $this->base = $urls->first() ?: self::DEFAULT_BASES[0];
        $this->fallbacks = $urls->slice(1)->all();
        $key = env('ANIME_API_KEY');
        $this->apiKey = is_string($key) && $key !== '' ? $key : null;
        $this->extra = $extra ?? new AnimeExtraSources();
    }

    /** Localhost resolvers can never work on Vercel. */
    protected function isLocalHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['127.0.0.1', 'localhost', '::1', '0.0.0.0'], true);
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
     * Fetch the resolver's episode aggregate for a title.
     *
     * Anivexa 2.x only exposes /episodes/{anilistId}. The per-provider
     * /episodes/{provider}/{id} route does not exist there and silently
     * answers with the API banner, which is why every episode list used to
     * be empty. The aggregate returns one block per provider:
     *
     *   { "anikoto": { "episodes": { "sub": [...], "dub": [...] } }, ... }
     *
     * It is slow for long-running titles (~30 s), so callers that already
     * know the aired episode count build a synthetic list instead and never
     * wait for it.
     */
    protected function fetchProviderEpisodes(int $anilistId): array
    {
        $results = $this->getJsonMulti([
            'aggregate' => $this->base . "/episodes/{$anilistId}",
        ], 20000);

        $data = $results['aggregate'] ?? null;
        if (!is_array($data)) {
            return [];
        }

        // Some builds nest the provider blocks under "providers".
        if (isset($data['providers']) && is_array($data['providers'])) {
            $data = $data['providers'];
        }

        $blocks = [];
        foreach ($data as $name => $block) {
            if (!is_array($block) || !isset($block['episodes']) || !is_array($block['episodes'])) {
                continue;
            }
            if (empty($block['episodes']['sub']) && empty($block['episodes']['dub'])) {
                continue;
            }
            $blocks[strtolower((string) $name)] = $block;
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

        // Cache the title list for playback fallbacks: a stream request only
        // carries the AniList id and episode number, but the hardsub sources
        // are searched by title.
        if (is_array($anime) && ! empty($anime)) {
            $this->extra->rememberTitles($anilistId, $anime);
        }

        $cacheKey = "anivexa_episodes_v4_{$anilistId}_{$audio}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && !empty($cached['episodes'])
            && ($expected === null || $expected <= 0 || count($cached['episodes']) >= $expected)) {
            return $cached;
        }

        // AniList already tells us how many episodes aired. Build the
        // deterministic Anivexa watch paths immediately so playback hits our
        // self-hosted api-eps-nine.vercel.app (direct HLS) instead of getting
        // hijacked by AnimeHeaven/Samehadaku scrapers.
        if ($expected !== null && $expected > 0) {
            $audioAvailable = $this->audioAvailability($anilistId);
            $audioAvailable[$audio] = true;
            $synthetic = $this->syntheticEpisodes($anilistId, $expected, $audio);
            if (!empty($synthetic)) {
                $result = [
                    'provider' => $this->priority[0] ?? 'anikoto',
                    'providers' => $this->priority,
                    'episodes' => $synthetic,
                    'audio' => $audio,
                    'audioAvailable' => array_merge($audioAvailable, [$audio => true]),
                    'synthetic' => true,
                ];
                Cache::put($cacheKey, $result, 3600);

                return $result;
            }
        }

        $responses = $this->fetchProviderEpisodes($anilistId);

        // Audio availability comes from the same response: providers expose
        // separate `episodes.sub` / `episodes.dub` arrays, so a title can
        // have 12 sub episodes and 3 dub episodes (or none at all).
        if (!empty($responses)) {
            $audioAvailable = $this->audioAvailabilityFrom($responses);
            Cache::put("anivexa_audio_{$anilistId}", $audioAvailable, 21600);
        } else {
            $audioAvailable = $this->audioAvailability($anilistId);
            // No aggregate to inspect: assume the requested track exists so
            // the toggle is usable. getStream() falls back to sub if the
            // provider has no dub track for that episode.
            $audioAvailable[$audio] = true;
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

        // Secondary sources are worth their latency whenever the resolver
        // produced no episode list at all, or when the requested dub track is
        // missing. A known AniList count no longer skips them: a title the
        // resolver does not carry would otherwise fall back to a synthetic
        // list that is guaranteed to fail on playback.
        $extraProvider = null;
        $needsExtra = empty($byNumber) || ($audio === 'dub' && empty($audioAvailable['dub']));
        if ($needsExtra) {
            foreach (['anikoto', 'hianime', 'consumet'] as $source) {
                $list = match ($source) {
                    'anikoto' => $this->extra->anikotoEpisodes($anime ?? [], $anilistId, $audio),
                    'hianime' => $this->extra->hianimeEpisodes($anime ?? [], $audio),
                    default => $this->extra->consumetEpisodes($anime ?? [], $audio),
                };
                foreach ($list as $ep) {
                    if (! isset($byNumber[$ep['number']])) {
                        $byNumber[$ep['number']] = $ep;
                    }
                }
                if (! empty($list)) {
                    $audioAvailable[$audio] = true;
                    $extraProvider = $source;
                }
            }
        }

        // Still nothing playable: fall back to a hard-subtitled file. These
        // are single MP4s with the subtitles burned into the frames, so they
        // work even when every soft-sub provider is missing an English track.
        // Dub requests are skipped because all hardsub libraries are sub-only.
        if (empty($byNumber) && $audio === 'sub') {
            foreach (['animeheaven', 'nimegami', 'kuramanime', 'samehadaku'] as $source) {
                $list = match ($source) {
                    'animeheaven' => $this->extra->animeHeavenEpisodes($anime ?? [], $anilistId),
                    'nimegami' => $this->extra->nimegamiEpisodes($anime ?? []),
                    'kuramanime' => $this->extra->kuramanimeEpisodes($anime ?? []),
                    default => $this->extra->samehadakuEpisodes($anime ?? []),
                };
                foreach ($list as $ep) {
                    if (! isset($byNumber[$ep['number']])) {
                        $byNumber[$ep['number']] = $ep;
                    }
                }
                if (! empty($list)) {
                    $audioAvailable['sub'] = true;
                    $extraProvider = $source;
                    break;
                }
            }
        }

        if (empty($byProvider) && empty($byNumber)) {
            if (is_array($cached) && !empty($cached['episodes'])) {
                return $cached;
            }

            $synthetic = $this->syntheticEpisodes($anilistId, $expected, $audio);
            if (!empty($synthetic)) {
                $result = [
                    'provider' => $this->priority[0] ?? 'anikoto',
                    'providers' => $this->priority,
                    'episodes' => $synthetic,
                    'audio' => $audio,
                    'audioAvailable' => array_merge($audioAvailable, [$audio => true]),
                    'synthetic' => true,
                ];
                Cache::put($cacheKey, $result, 3600);

                return $result;
            }

            return array_merge($empty, ['audioAvailable' => $audioAvailable]);
        }

        if (! empty($byProvider)) {
            Cache::put("anivexa_episodes_by_provider_{$anilistId}_{$audio}", $byProvider, 21600);
        }
        Cache::put("anivexa_audio_{$anilistId}", $audioAvailable, 21600);

        ksort($byNumber);
        $primary = array_key_first($byProvider) ?: $extraProvider;
        $providers = array_keys($byProvider);
        if (empty($providers) && $extraProvider !== null) {
            $providers = [$extraProvider];
        }
        $result = [
            'provider' => $primary,
            'providers' => $providers,
            'episodes' => array_values($byNumber),
            'audio' => $audio,
            'audioAvailable' => $audioAvailable,
        ];
        Cache::put($cacheKey, $result, 21600);
        return $result;
    }

    /**
     * Build an AniList-driven episode list when the resolver returns none.
     * Watch paths are deterministic, so playback can still resolve an
     * episode by number on demand.
     */
    protected function syntheticEpisodes(int $anilistId, ?int $expected, string $audio): array
    {
        $count = (int) ($expected ?? 0);
        if ($count < 1) {
            return [];
        }

        $provider = $this->priority[0] ?? 'anikoto';
        $episodes = [];
        for ($number = 1; $number <= $count; $number++) {
            $episodes[] = [
                'id' => 'watch/' . $provider . '/' . $anilistId . '/' . $audio . '/' . $provider . '-' . $number,
                'number' => $number,
                'title' => '',
                'image' => null,
                'duration' => null,
            ];
        }

        return $episodes;
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
     * Whether the Dub toggle should be offered.
     *
     * A real probe costs 10-15 s per title (the resolver resolves the dub
     * stream before answering), which is far too much for a page render.
     * The toggle is therefore optimistic: when availability is unknown we
     * show it, and getStream() silently falls back to the sub track if the
     * provider has no dub for that episode. A cached "no dub" answer still
     * hides it.
     */
    public function dubAvailable(int $anilistId): bool
    {
        if ($anilistId <= 0) {
            return false;
        }

        $cached = Cache::get("anivexa_audio_{$anilistId}");

        return is_array($cached) ? ! empty($cached['dub']) : true;
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
        // Read-only on purpose: calling getEpisodes() here would pull the
        // slow aggregate endpoint inside a playback failover.
        $byProvider = Cache::get("anivexa_episodes_by_provider_{$anilistId}_{$audio}");
        if (!is_array($byProvider)) {
            return null;
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
        if (! str_starts_with($reference, 'hn|')
            && ! str_starts_with($reference, 'cs|')
            && ! str_starts_with($reference, 'ak|')
            && ! str_starts_with($reference, 'ah|')
            && ! str_starts_with($reference, 'ng|')
            && ! str_starts_with($reference, 'km|')
            && ! str_starts_with($reference, 'sd|')) {
            return null;
        }

        $cacheKey = 'anime_extra_stream_' . md5($reference);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        if (str_starts_with($reference, 'ak|')) {
            $stream = $this->extra->anikotoStream($reference);
        } elseif (str_starts_with($reference, 'ah|')) {
            $stream = $this->extra->animeHeavenStream($reference);
        } elseif (str_starts_with($reference, 'ng|')) {
            $stream = $this->extra->nimegamiStream($reference);
        } elseif (str_starts_with($reference, 'km|')) {
            $stream = $this->extra->kuramanimeStream($reference);
        } elseif (str_starts_with($reference, 'sd|')) {
            $stream = $this->extra->samehadakuStream($reference);
        } elseif (str_starts_with($reference, 'hn|')) {
            $stream = $this->extra->hianimeStream($reference, 'sub');
        } else {
            $stream = $this->extra->consumetStream($reference);
        }

        if ($stream) {
            Cache::put($cacheKey, $stream, 3600);
        }

        return $stream;
    }

    public function getStream(
        string $watchPath,
        ?string $providerOverride = null,
        ?int $anilistId = null,
        ?int $episodeNumber = null
    ): ?array {
        // A burned-in mode is an explicit user choice: resolve that copy
        // before anything else, so an episode id that already points at a
        // different hardsub library cannot silently win.
        $forced = $this->hardsubMode($providerOverride);
        if ($forced !== null) {
            $forcedPath = $this->parseWatchPath($watchPath);
            $forcedId = $forcedPath['anilistId'] ?? $anilistId;
            $forcedEp = isset($forcedPath['number']) ? (int) $forcedPath['number'] : $episodeNumber;
            if ($forcedId && $forcedEp && $forcedEp >= 1) {
                $hardsub = $this->hardsubFor((int) $forcedId, (int) $forcedEp, $forced['id']);
                if ($hardsub !== null) {
                    $hardsub['hard_sub_lang'] = $forced['lang'];

                    return $hardsub;
                }
            }
        }

        // Episodes that came from a secondary source route back to it.
        $extraStream = $this->extraStream($watchPath);
        if ($extraStream !== null) {
            if ($forced !== null && ($extraStream['hard_sub_lang'] ?? null) !== $forced['lang']) {
                $extraStream['hardsub_fallback'] = $forced['lang'];
            }

            return $extraStream;
        }

        $parsed = $this->parseWatchPath($watchPath);
        $audio = $parsed['audio'] ?? 'sub';

        if ($forced === null && $providerOverride && $parsed && $providerOverride !== $parsed['provider']) {
            $alt = $this->alternativePath($parsed['anilistId'], $parsed['number'], $providerOverride, $audio);
            if ($alt) {
                $watchPath = $alt;
                $parsed = $this->parseWatchPath($watchPath);
            }
        }

        $cacheKey = 'anivexa_stream_v7_' . md5($watchPath . '|' . ($providerOverride ?? ''));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $stream = $this->fetchStream($watchPath);
        $fallbackEmbed = null;
        if ($stream && ($stream['kind'] ?? 'hls') === 'embed') {
            $fallbackEmbed = $stream;
            $stream = null;
        }

        // Failover: if the episode's own provider failed OR only returned a
        // third-party embed, try up to 2 alternates for a direct ad-free HLS
        // stream before falling back to the embed.
        if (!$stream && $parsed) {
            $byProvider = Cache::get("anivexa_episodes_by_provider_{$parsed['anilistId']}_{$audio}");
            $order = is_array($byProvider) && !empty($byProvider)
                ? $this->providerOrder(array_keys($byProvider))
                : $this->priority;
            $tried = 0;
            foreach ($order as $name) {
                if ($name === $parsed['provider']) {
                    continue;
                }
                if ($tried++ >= 2) {
                    break;
                }
                $number = $parsed['number'] == (int) $parsed['number']
                    ? (string) (int) $parsed['number']
                    : (string) $parsed['number'];
                $alt = $this->alternativePath($parsed['anilistId'], $parsed['number'], $name, $audio)
                    ?: 'watch/' . $name . '/' . $parsed['anilistId'] . '/' . $audio . '/' . $name . '-' . $number;
                $candidate = $this->fetchStream($alt);
                if (!$candidate) {
                    continue;
                }
                $candidate['provider'] = $name;
                if (($candidate['kind'] ?? 'hls') === 'embed') {
                    $fallbackEmbed ??= $candidate;
                    continue;
                }
                $stream = $candidate;
                break;
            }
        }

        // Last resort: use an embed only if no direct HLS stream was found.
        if (!$stream && $fallbackEmbed) {
            $stream = $fallbackEmbed;
        }

        // Dub requested but no provider carries it: play the sub version of
        // the same episode instead of ending on an error screen. The player
        // shows a small note when audio_fallback is set.
        if (!$stream && $parsed && $audio === 'dub') {
            $number = $parsed['number'] == (int) $parsed['number']
                ? (string) (int) $parsed['number']
                : (string) $parsed['number'];
            $subPath = 'watch/' . $parsed['provider'] . '/' . $parsed['anilistId'] . '/sub/' . $parsed['provider'] . '-' . $number;
            $stream = $this->fetchStream($subPath);
            if ($stream) {
                $stream['audio_fallback'] = 'sub';
                Cache::put("anivexa_audio_{$parsed['anilistId']}", ['sub' => true, 'dub' => false], 3600);
            }
        }

        // Last resort: a copy with the subtitles burned into the video. It
        // has no tracks to sync, so it still works when every provider in
        // the chain shipped an unusable or non-English subtitle track.
        if (! $stream && $parsed && $audio === 'sub') {
            $stream = $this->hardsubFor($parsed['anilistId'], (int) $parsed['number']);
        }

        // The requested burned-in language was not available and the player
        // is getting something else. Flag it so the UI can say so instead of
        // pretending the subtitles are burned in.
        if ($stream && $forced !== null && ($stream['hard_sub_lang'] ?? null) !== $forced['lang']) {
            $stream['hardsub_fallback'] = $forced['lang'];
        }

        if ($stream && empty($stream['hardsub_fallback'])) {
            Cache::put($cacheKey, $stream, 3600);
        }
        return $stream;
    }

    /**
     * Hard-subtitled stream for one episode, optionally pinned to a source.
     * Titles are read from the cache filled by getEpisodes().
     *
     * @return array<string, mixed>|null
     */
    protected function hardsubFor(int $anilistId, int $number, ?string $source = null): ?array
    {
        if ($anilistId <= 0 || $number < 1) {
            return null;
        }

        $titles = Cache::get("anime_titles_{$anilistId}");
        if (! is_array($titles) || empty($titles)) {
            return null;
        }

        $anime = [
            'title' => (string) ($titles[0] ?? ''),
            'altTitles' => array_values(array_slice($titles, 1)),
        ];

        if ($source === 'animeheaven') {
            $stream = $this->extra->animeHeavenStreamFor($anime, $number);

            return is_array($stream) && ($stream['kind'] ?? '') === 'mp4' ? $stream : null;
        }

        if ($source === 'id') {
            // Walk every Indonesian burned-in library until one yields a
            // direct MP4 (refusing iframe embeds so "Burned-in" never lies).
            foreach (['nimegami', 'kuramanime', 'samehadaku'] as $idSource) {
                $stream = match ($idSource) {
                    'nimegami' => $this->extra->nimegamiStreamFor($anime, $number),
                    'kuramanime' => $this->extra->kuramanimeStreamFor($anime, $number),
                    default => $this->extra->samehadakuStreamFor($anime, $number),
                };
                if (is_array($stream) && ($stream['kind'] ?? '') === 'mp4') {
                    return $stream;
                }
            }

            return null;
        }

        if (in_array($source, ['nimegami', 'kuramanime', 'samehadaku'], true)) {
            $stream = match ($source) {
                'nimegami' => $this->extra->nimegamiStreamFor($anime, $number),
                'kuramanime' => $this->extra->kuramanimeStreamFor($anime, $number),
                default => $this->extra->samehadakuStreamFor($anime, $number),
            };

            // The picker promises a file with the subtitles burned into the
            // frames. An iframe mirror may well be a hardsub too,
            // but it is a third-party page, not something we can call
            // "Burned-in" — refuse it rather than show the wrong thing.
            return is_array($stream) && ($stream['kind'] ?? '') === 'mp4' ? $stream : null;
        }

        return $this->extra->hardsubStreamFor($anilistId, $number);
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
        // API 2.x wraps the playable block in a key such as "ssub"/"sdub".
        foreach ($data as $value) {
            if (is_array($value) && (isset($value['streams']) || isset($value['sources']))) {
                $data = array_merge($data, $value);
                break;
            }
        }
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
        // Encrypted manifests (e.g. reanime's playlist_key) cannot be parsed
        // as plain #EXTM3U by hls.js or our playlist proxy.
        if (!empty($stream['playlist_key'])) {
            return false;
        }
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

        // API 2.x returns { "ssub": { "streams": [...], "subtitles": [...] } }
        // or the same block under "sdub"; lift it to the top level so the
        // normaliser below sees streams and subtitles in one place.
        foreach ($data as $value) {
            if (is_array($value) && (isset($value['streams']) || isset($value['sources']))) {
                $data = array_merge($data, $value);
                break;
            }
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

        $hls = null;
        $embed = null;
        foreach ($streams as $s) {
            $url = $this->streamUrl($s);
            if ($url === '') {
                continue;
            }
            if ($this->isHlsStream($s, $url)) {
                if (($s['isActive'] ?? $s['active'] ?? true)) {
                    $hls = $s;
                    $hls['url'] = $url;
                    break;
                }
                if ($hls === null) {
                    $hls = $s;
                    $hls['url'] = $url;
                }
                continue;
            }
            if ($embed === null) {
                $embed = $s;
                $embed['url'] = $url;
            }
        }

        $chosen = $hls ?? $embed;
        if (!$chosen) {
            return null;
        }
        $isEmbed = $hls === null;

        $subtitles = [];
        $tracks = $chosen['subtitles'] ?? $chosen['tracks'] ?? $data['subtitles'] ?? $data['tracks'] ?? [];
        foreach ((is_array($tracks) ? $tracks : []) as $sub) {
            $subUrl = (string) ($sub['url'] ?? $sub['file'] ?? $sub['src'] ?? '');
            if ($subUrl === '') {
                continue;
            }
            $kind = strtolower((string) ($sub['kind'] ?? ''));
            $label = trim((string) ($sub['label'] ?? ''));
            if ($kind === 'thumbnails' || ($label === '' && str_contains($subUrl, 'thumbnail'))) {
                continue;
            }
            $lang = strtolower((string) ($sub['language'] ?? $sub['srclang'] ?? $sub['lang'] ?? ''));
            if ($lang === '') {
                $lang = strtolower($label);
            }
            $subtitles[] = [
                'lang' => $lang,
                'label' => $label !== '' ? $label : strtoupper($lang ?: 'EN'),
                'url' => $subUrl,
            ];
        }

        $parsed = $this->parseWatchPath($watchPath);

        return [
            'sources' => [[
                'url' => $chosen['url'],
                'quality' => 'auto',
                'isM3U8' => ! $isEmbed,
            ]],
            'subtitles' => $isEmbed ? [] : $subtitles,
            'kind' => $isEmbed ? 'embed' : 'hls',
            'embed' => $isEmbed ? null : ($embed['url'] ?? null),
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

    /**
     * Playback modes offered by the player: the standard soft-sub chain plus
     * every burned-in library that is enabled.
     *
     * @return array<int, array{id: string, label: string, lang: string|null, hardSub: bool}>
     */
    public function modes(): array
    {
        return $this->extra->hardsubModes();
    }

    /**
     * Cache every known title of a catalogue row so a burned-in request can
     * find its copy by title. Called while rendering the watch page, before
     * the browser asks for the stream.
     *
     * @param  array<string, mixed>  $anime
     */
    public function rememberTitles(array $anime): void
    {
        $id = (int) ($anime['anilistId'] ?? preg_replace('/[^0-9]/', '', (string) ($anime['id'] ?? '')));
        if ($id > 0) {
            $this->extra->rememberTitles($id, $anime);
        }
    }

    /**
     * Metadata for a burned-in mode id, or null when the id is not one.
     *
     * @return array{id: string, label: string, lang: string|null, hardSub: bool}|null
     */
    protected function hardsubMode(?string $id): ?array
    {
        return $this->extra->hardsubMode($id);
    }
}

