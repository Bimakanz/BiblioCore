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

    public function __construct()
    {
        $this->hianime = $this->normalise(env('HIANIME_API_URL', 'https://api-aniwatch.onrender.com'));
        $this->consumet = $this->normalise(env('CONSUMET_BASE_URL'));
        $this->arm = $this->normalise(env('ARM_API_URL', 'https://arm.haglund.dev'));
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

    /** AniList -> MAL / AniDB / TMDB ids (ARM API). */
    public function ids(int $anilistId): array
    {
        if (! $this->arm) {
            return [];
        }

        return Cache::remember("anime_arm_ids_{$anilistId}", 2592000, function () use ($anilistId) {
            return $this->get($this->arm . '/api/v2/ids?source=anilist&id=' . $anilistId) ?? [];
        });
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
