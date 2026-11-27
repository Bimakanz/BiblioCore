<?php

namespace App\Services\Anime;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Secondary episode/stream sources used when the primary Anivexa resolver has
 * nothing playable (typical for a freshly airing cour, and for dub tracks the
 * Anivexa providers do not carry yet).
 *
 * Everything here is optional and fails soft: a dead instance or a changed
 * payload shape returns an empty list and the player keeps whatever Anivexa
 * produced. Bases come from HIAnime / Consumet / ARM env vars.
 */
class AnimeExtraSources
{
    protected ?string $hianime;

    protected ?string $consumet;

    protected ?string $arm;

    /** Anivexa base, used for the /map cross-id endpoint. */
    protected ?string $resolver;

    protected AnikotoSource $anikoto;

    /** English hard-subtitled MP4 library. */
    protected AnimeHeavenSource $animeHeaven;

    /** Indonesian hard-subtitled MP4 library (primary, ~3,000+ titles). */
    protected NimegamiSource $nimegami;

    /** Indonesian hard-subtitled MP4 library (Playwright worker, optional). */
    protected KuramanimeSource $kuramanime;

    /** Indonesian hard-subtitled MP4 library (fallback). */
    protected SamehadakuSource $samehadaku;

    public function __construct(
        ?AnikotoSource $anikoto = null,
        ?AnimeHeavenSource $animeHeaven = null,
        ?SamehadakuSource $samehadaku = null,
        ?NimegamiSource $nimegami = null,
        ?KuramanimeSource $kuramanime = null
    ) {
        // HiAnime shut down in 2026 and the old public wrapper now answers
        // 503 "Service Suspended". The code path stays available for a
        // self-hosted instance, but it is opt-in so a dead host never costs
        // a timeout on every playback fallback.
        $this->hianime = $this->normalise(env('HIANIME_API_URL'));
        $this->consumet = $this->normalise(env('CONSUMET_BASE_URL'));
        $this->arm = $this->normalise(env('ARM_API_URL', 'https://arm.haglund.dev'));
        $this->resolver = $this->normalise(env('ANIME_API_URL', 'https://api-eps-nine.vercel.app'));
        if ($this->resolver !== null && $this->isLocalHost($this->resolver)) {
            $this->resolver = 'https://api-eps-nine.vercel.app';
        }
        $this->anikoto = $anikoto ?? new AnikotoSource();
        $this->animeHeaven = $animeHeaven ?? new AnimeHeavenSource();
        $this->nimegami = $nimegami ?? new NimegamiSource();
        $this->kuramanime = $kuramanime ?? new KuramanimeSource();
        $this->samehadaku = $samehadaku ?? new SamehadakuSource();
    }

    protected function isLocalHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, ['127.0.0.1', 'localhost', '::1', '0.0.0.0'], true);
    }

    protected function normalise(?string $url): ?string
    {
        $url = trim((string) $url);

        return $url === '' ? null : rtrim($url, '/');
    }

    protected function get(string $url): ?array
    {
        try {
            $res = Http::timeout(6)->acceptJson()->get($url);
            $data = $res->ok() ? $res->json() : null;
        } catch (\Throwable $e) {
            Log::debug('anime extra source failed: ' . $e->getMessage(), ['url' => $url]);

            return null;
        }

        return is_array($data) ? $data : null;
    }

    /**
     * Cross-platform ids for an AniList title.
     *
     * Anivexa's /map endpoint answers with MAL, AniDB, Kitsu, TMDB and IMDb
     * in one call, so it is tried first; the ARM API remains as a fallback
     * for resolver builds that do not expose the route yet.
     */
    public function ids(int $anilistId): array
    {
        if ($anilistId <= 0) {
            return [];
        }

        return Cache::remember("anime_cross_ids_{$anilistId}", 2592000, function () use ($anilistId) {
            $ids = [];

            if ($this->resolver) {
                $data = $this->get($this->resolver.'/map/'.$anilistId);
                $map = $data['mappings'] ?? null;
                if (is_array($map)) {
                    foreach (['malId', 'aniId', 'anidbId', 'kitsuId', 'themoviedbId', 'imdbId'] as $field) {
                        if (! empty($map[$field])) {
                            $ids[$field] = $map[$field];
                        }
                    }
                }
            }

            if ($this->arm && empty($ids['malId'])) {
                $arm = $this->get($this->arm.'/api/v2/ids?source=anilist&id='.$anilistId);
                if (is_array($arm)) {
                    foreach ($arm as $field => $value) {
                        if (! isset($ids[$field]) && ! empty($value)) {
                            $ids[$field] = $value;
                        }
                    }
                }
            }

            return $ids;
        });
    }

    /**
     * Anikoto episodes (MegaPlay library) for one audio track. Episode ids
     * look like "ak|{seriesId}|{number}|{audio}" so the resolver routes the
     * playback request back here.
     *
     * @param  array<string, mixed>  $anime
     * @return array<int, array<string, mixed>>
     */
    public function anikotoEpisodes(array $anime, int $anilistId, string $audio): array
    {
        return $this->anikoto->episodesForAniList($anilistId, $audio, $anime);
    }

    /** Resolve an "ak|..." reference into a MegaPlay embed. */
    public function anikotoStream(string $reference): ?array
    {
        return $this->anikoto->streamFor($reference);
    }

    /**
     * English hard-subtitled episodes (MP4, subtitles burned in).
     *
     * @param  array<string, mixed>  $anime
     * @return array<int, array<string, mixed>>
     */
    public function animeHeavenEpisodes(array $anime, int $anilistId): array
    {
        return $this->animeHeaven->episodesForAniList($anilistId, $anime);
    }

    /** Resolve an "ah|..." reference into direct MP4 sources. */
    public function animeHeavenStream(string $reference): ?array
    {
        return $this->animeHeaven->streamFor($reference);
    }

    /**
     * @param  array<string, mixed>  $anime
     * @return array<string, mixed>|null
     */
    public function animeHeavenStreamFor(array $anime, int $number): ?array
    {
        return $this->animeHeaven->streamForEpisode($anime, $number);
    }

    /**
     * Indonesian hard-subtitled episodes (Nimegami MP4 library).
     *
     * @param  array<string, mixed>  $anime
     * @return array<int, array<string, mixed>>
     */
    public function nimegamiEpisodes(array $anime): array
    {
        return $this->nimegami->episodesFor($anime);
    }

    /** Resolve an "ng|..." reference into direct MP4 sources. */
    public function nimegamiStream(string $reference): ?array
    {
        return $this->nimegami->streamFor($reference);
    }

    /**
     * @param  array<string, mixed>  $anime
     * @return array<string, mixed>|null
     */
    public function nimegamiStreamFor(array $anime, int $number): ?array
    {
        return $this->nimegami->streamForEpisode($anime, $number);
    }

    /**
     * Indonesian hard-subtitled episodes (Kuramanime Playwright worker).
     *
     * @param  array<string, mixed>  $anime
     * @return array<int, array<string, mixed>>
     */
    public function kuramanimeEpisodes(array $anime): array
    {
        return $this->kuramanime->episodesFor($anime);
    }

    /** Resolve a "km|..." reference into direct MP4 sources. */
    public function kuramanimeStream(string $reference): ?array
    {
        return $this->kuramanime->streamFor($reference);
    }

    /**
     * @param  array<string, mixed>  $anime
     * @return array<string, mixed>|null
     */
    public function kuramanimeStreamFor(array $anime, int $number): ?array
    {
        return $this->kuramanime->streamForEpisode($anime, $number);
    }

    /**
     * Indonesian hard-subtitled episodes (MP4, subtitles burned in).
     *
     * @param  array<string, mixed>  $anime
     * @return array<int, array<string, mixed>>
     */
    public function samehadakuEpisodes(array $anime): array
    {
        return $this->samehadaku->episodesFor($anime);
    }

    /** Resolve an "sd|..." reference into direct MP4 sources. */
    public function samehadakuStream(string $reference): ?array
    {
        return $this->samehadaku->streamFor($reference);
    }

    /**
     * @param  array<string, mixed>  $anime
     * @return array<string, mixed>|null
     */
    public function samehadakuStreamFor(array $anime, int $number): ?array
    {
        return $this->samehadaku->streamForEpisode($anime, $number);
    }

    /**
     * Remember every known title for a title, so playback can find a hardsub
     * copy later without the catalogue row being passed around again.
     *
     * @param  array<string, mixed>  $anime
     */
    public function rememberTitles(int $anilistId, array $anime): void
    {
        if ($anilistId <= 0) {
            return;
        }

        $titles = $this->allTitles($anime);
        if (! empty($titles)) {
            Cache::put("anime_titles_{$anilistId}", $titles, 2592000);
        }
    }

    /**
     * Burned-in playback modes the player can offer, in display order.
     *
     * "Standard" is the normal soft-sub chain; the rest are single MP4 files
     * with the subtitles already inside the frames. A disabled source (empty
     * *_URL env) is simply not offered.
     *
     * @return array<int, array{id: string, label: string, lang: string|null, hardSub: bool}>
     */
    public function hardsubModes(): array
    {
        $modes = [[
            'id' => '',
            'label' => 'Standard',
            'lang' => null,
            'hardSub' => false,
        ]];

        if ($this->animeHeaven->enabled()) {
            $modes[] = [
                'id' => 'animeheaven',
                'label' => 'Burned-in · EN',
                'lang' => 'en',
                'hardSub' => true,
            ];
        }

        if ($this->nimegami->enabled() || $this->kuramanime->enabled() || $this->samehadaku->enabled()) {
            $modes[] = [
                'id' => 'id',
                'label' => 'Burned-in · ID',
                'lang' => 'id',
                'hardSub' => true,
            ];
        }

        return $modes;
    }

    /**
     * Metadata for one burned-in mode id ("animeheaven", "id", "nimegami",
     * "kuramanime" or "samehadaku").
     *
     * @return array{id: string, label: string, lang: string|null, hardSub: bool}|null
     */
    public function hardsubMode(?string $id): ?array
    {
        $id = trim((string) $id);
        if ($id === '') {
            return null;
        }

        foreach ($this->hardsubModes() as $mode) {
            if ($mode['id'] === $id) {
                return $mode;
            }
        }

        if ($id === 'nimegami' && $this->nimegami->enabled()) {
            return [
                'id' => 'nimegami',
                'label' => 'Burned-in · ID',
                'lang' => 'id',
                'hardSub' => true,
            ];
        }

        if ($id === 'kuramanime' && $this->kuramanime->enabled()) {
            return [
                'id' => 'kuramanime',
                'label' => 'Burned-in · ID',
                'lang' => 'id',
                'hardSub' => true,
            ];
        }

        if ($id === 'samehadaku' && $this->samehadaku->enabled()) {
            return [
                'id' => 'samehadaku',
                'label' => 'Burned-in · ID',
                'lang' => 'id',
                'hardSub' => true,
            ];
        }

        return null;
    }

    /**
     * Last-resort hard-subtitled copy of one episode: English first (the UI
     * is English), then Indonesian (Nimegami, Kuramanime, then Samehadaku).
     * Returns null when none of them carries it.
     *
     * @return array<string, mixed>|null
     */
    public function hardsubStreamFor(int $anilistId, int $number): ?array
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

        foreach ([$this->animeHeaven, $this->nimegami, $this->kuramanime, $this->samehadaku] as $source) {
            $stream = $source->streamForEpisode($anime, $number);
            if (is_array($stream)) {
                return $stream;
            }
        }

        return null;
    }

    /**
     * Every title a catalogue row carries (not just the search shortlist).
     *
     * @param  array<string, mixed>  $anime
     * @return array<int, string>
     */
    protected function allTitles(array $anime): array
    {
        $titles = [(string) ($anime['title'] ?? '')];
        foreach (['titles', 'altTitles', 'synonyms'] as $field) {
            $value = $anime[$field] ?? null;
            if (is_string($value)) {
                $titles[] = $value;
                continue;
            }
            foreach ((is_array($value) ? $value : []) as $title) {
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

        return $out;
    }

    /** Best-effort HiAnime slug lookup, cached for a day. */
    protected function hianimeSlug(array $anime): ?string
    {
        if (! $this->hianime) {
            return null;
        }

        $key = 'hianime_slug_' . md5((string) ($anime['id'] ?? ''));

        return Cache::remember($key, 86400, function () use ($anime) {
            foreach ($this->titleCandidates($anime) as $title) {
                $data = $this->get($this->hianime . '/api/v2/hianime/search?q=' . urlencode($title));
                $list = $data['data']['animes'] ?? $data['animes'] ?? [];
                if (! is_array($list) || empty($list)) {
                    continue;
                }
                $slug = $list[0]['id'] ?? null;
                if (is_string($slug) && $slug !== '') {
                    return $slug;
                }
            }

            return null;
        });
    }

    /** Titles tried in order when searching a third-party catalogue. */
    protected function titleCandidates(array $anime): array
    {
        $titles = [];
        $titles[] = $anime['title'] ?? '';
        foreach (($anime['titles'] ?? []) as $t) {
            $titles[] = $t;
        }
        foreach (($anime['altTitles'] ?? []) as $t) {
            $titles[] = $t;
        }

        $out = [];
        foreach ($titles as $t) {
            $t = trim((string) $t);
            if ($t !== '' && ! in_array($t, $out, true)) {
                $out[] = $t;
            }
        }

        return array_slice($out, 0, 3);
    }

    /**
     * HiAnime episodes for one audio track. Episode ids look like
     * "hn|{animeSlug}|{episodeId}" so the resolver can route them back here.
     */
    public function hianimeEpisodes(array $anime, string $audio): array
    {
        $slug = $this->hianimeSlug($anime);
        if (! $slug) {
            return [];
        }

        $candidates = $audio === 'dub' ? [$slug . '-dub', $slug] : [$slug];
        foreach ($candidates as $candidate) {
            $data = $this->get($this->hianime . '/api/v2/hianime/anime/' . rawurlencode($candidate) . '/episodes');
            $list = $data['data']['episodes'] ?? $data['episodes'] ?? [];
            if (! is_array($list) || empty($list)) {
                continue;
            }
            $out = [];
            foreach ($list as $ep) {
                $epId = (string) ($ep['episodeId'] ?? $ep['id'] ?? '');
                if ($epId === '') {
                    continue;
                }
                $out[] = [
                    'id' => 'hn|' . $candidate . '|' . $epId . '|' . $audio,
                    'number' => (int) ($ep['number'] ?? count($out) + 1),
                    'title' => (string) ($ep['title'] ?? ''),
                    'image' => null,
                    'duration' => null,
                ];
            }
            if (! empty($out)) {
                usort($out, fn ($a, $b) => $a['number'] <=> $b['number']);

                return $out;
            }
        }

        return [];
    }

    /** Resolve an "hn|{slug}|{episodeId}" reference into HLS sources. */
    public function hianimeStream(string $reference, string $audio): ?array
    {
        $parts = explode('|', $reference);
        $episodeId = $parts[2] ?? '';
        $audio = ($parts[3] ?? $audio) === 'dub' ? 'dub' : 'sub';
        if (! $this->hianime || $episodeId === '') {
            return null;
        }

        $url = $this->hianime . '/api/v2/hianime/episode/sources?animeEpisodeId='
            . rawurlencode($episodeId) . '&category=' . ($audio === 'dub' ? 'dub' : 'sub');

        return $this->normaliseStream($this->get($url), 'HiAnime');
    }

    /** Episodes from a Consumet instance (gogoanime, sub or dub). */
    public function consumetEpisodes(array $anime, string $audio): array
    {
        if (! $this->consumet) {
            return [];
        }

        $key = 'consumet_info_' . md5((string) ($anime['id'] ?? '') . '|' . $audio);
        $info = Cache::remember($key, 86400, function () use ($anime, $audio) {
            foreach ($this->titleCandidates($anime) as $title) {
                $data = $this->get($this->consumet . '/anime/gogoanime/' . urlencode($title));
                foreach (($data['results'] ?? []) as $result) {
                    $kind = strtolower((string) ($result['subOrDub'] ?? ''));
                    if ($audio === 'dub' ? $kind !== 'dub' : $kind === 'dub') {
                        continue;
                    }
                    $id = $result['id'] ?? null;
                    if (is_string($id) && $id !== '') {
                        return $this->get($this->consumet . '/anime/gogoanime/info/' . rawurlencode($id)) ?? [];
                    }
                }
            }

            return [];
        });

        $out = [];
        foreach (($info['episodes'] ?? []) as $ep) {
            $epId = (string) ($ep['id'] ?? '');
            if ($epId === '') {
                continue;
            }
            $out[] = [
                'id' => 'cs|gogoanime|' . $epId . '|' . $audio,
                'number' => (int) ($ep['number'] ?? count($out) + 1),
                'title' => '',
                'image' => null,
                'duration' => null,
            ];
        }

        usort($out, fn ($a, $b) => $a['number'] <=> $b['number']);

        return $out;
    }

    /** Resolve a "cs|{provider}|{episodeId}" reference into HLS sources. */
    public function consumetStream(string $reference): ?array
    {
        $parts = explode('|', $reference);
        $provider = $parts[1] ?? 'gogoanime';
        $episodeId = $parts[2] ?? '';
        if (! $this->consumet || $episodeId === '') {
            return null;
        }

        $url = $this->consumet . '/anime/' . rawurlencode($provider) . '/watch/' . rawurlencode($episodeId);

        return $this->normaliseStream($this->get($url), 'Consumet');
    }

    /** Flatten a consumet/aniwatch-shaped payload into our stream contract. */
    protected function normaliseStream(?array $data, string $label): ?array
    {
        if (! $data) {
            return null;
        }

        $sources = $data['sources'] ?? $data['data']['sources'] ?? [];
        $tracks = $data['tracks'] ?? $data['data']['tracks'] ?? [];
        $chosen = null;
        foreach ((is_array($sources) ? $sources : []) as $source) {
            $url = (string) ($source['url'] ?? $source['file'] ?? '');
            if ($url === '') {
                continue;
            }
            $isHls = ! empty($source['isM3U8']) || str_contains($url, '.m3u8');
            if (! $isHls) {
                continue;
            }
            $chosen = $url;
            break;
        }

        if ($chosen === null) {
            return null;
        }

        $subtitles = [];
        foreach ((is_array($tracks) ? $tracks : []) as $track) {
            $subUrl = (string) ($track['url'] ?? $track['file'] ?? '');
            if ($subUrl === '') {
                continue;
            }
            $lang = strtolower((string) ($track['lang'] ?? $track['srclang'] ?? $track['label'] ?? 'en'));
            $subtitles[] = [
                'lang' => $lang,
                'label' => (string) ($track['label'] ?? strtoupper($lang)),
                'url' => $subUrl,
            ];
        }

        return [
            'sources' => [['url' => $chosen, 'quality' => 'auto', 'isM3U8' => true]],
            'subtitles' => $subtitles,
            'referer' => null,
            'intro' => null,
            'outro' => null,
            'provider' => $label,
            'server' => null,
        ];
    }
}
