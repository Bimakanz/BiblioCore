<?php

namespace App\Services\Manga\Providers;

use App\Services\Manga\Support\ChapterLabel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Atsumaru (atsu.moe) — English manga/manhwa/manhua chapter source.
 *
 * The HTML pages sit behind Cloudflare, but the JSON API the site itself uses
 * is public and answers server-side requests, so a provider can talk to it
 * without a browser: search (Typesense-backed), full chapter list, and the
 * reader page list. Pages live on cdn.atsu.moe and are proxied through
 * wsrv.nl like every other provider.
 *
 * Only English chapters exist on Atsumaru, so the provider is wired into the
 * English branch of MangaService and never answers an Indonesian request.
 */
class AtsumaruProvider
{
    protected string $base;

    protected const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    protected const CDN = 'https://cdn.atsu.moe';

    public function __construct()
    {
        $this->base = rtrim((string) env('ATSUMARU_API_URL', 'https://atsu.moe'), '/');
    }

    /**
     * English chapter list for a title, resolved by name.
     *
     * @param  array<int, string>  $altTitles
     * @return array<int, array<string, mixed>>
     */
    public function getChapters(string $title, array $altTitles = []): array
    {
        $cacheKey = 'atsu_chapters_v1_'.md5(strtolower(trim($title)));

        $result = Cache::remember($cacheKey, 43200, function () use ($title, $altTitles) {
            try {
                $manga = $this->findManga($title, $altTitles);
                if (! $manga) {
                    return [];
                }

                $mangaId = (string) $manga['id'];
                $data = $this->getJson('/api/manga/allChapters', ['mangaId' => $mangaId]);
                $list = $data['chapters'] ?? null;
                if (! is_array($list)) {
                    return [];
                }

                $chapters = [];
                foreach ($list as $chapter) {
                    if (! is_array($chapter)) {
                        continue;
                    }

                    $chapterId = trim((string) ($chapter['id'] ?? ''));
                    if ($chapterId === '') {
                        continue;
                    }

                    $number = ChapterLabel::number(
                        (string) ($chapter['number'] ?? $chapter['title'] ?? ''),
                        (int) ($chapter['index'] ?? 0) + 1
                    );

                    $id = 'atsu_'.$chapterId;
                    Cache::put('atsu_meta_'.$id, [
                        'mangaId' => $mangaId,
                        'chapterId' => $chapterId,
                        'number' => $number,
                    ], 2592000);

                    $chapters[] = [
                        'id' => $id,
                        'chapter' => $number,
                        'title' => (string) ($chapter['title'] ?? ''),
                        'language' => 'en',
                        'group' => 'Atsumaru',
                        'provider' => 'Atsumaru',
                        'publishAt' => $this->publishedAt($chapter['createdAt'] ?? null),
                        'externalUrl' => null,
                        'readPath' => $this->base.'/manga/'.$mangaId,
                    ];
                }

                return $chapters;
            } catch (\Throwable $e) {
                Log::warning('Atsumaru chapter fetch error: '.$e->getMessage());

                return [];
            }
        });

        if (empty($result)) {
            Cache::forget($cacheKey);
        }

        return $result;
    }

    /**
     * Readable pages for one Atsumaru chapter.
     *
     * @return array<string, mixed>|null
     */
    public function getChapterPages(string $chapterId): ?array
    {
        $cacheKey = 'atsu_pages_'.md5($chapterId);

        return Cache::remember($cacheKey, 604800, function () use ($chapterId) {
            $meta = Cache::get('atsu_meta_'.$chapterId);
            if (! is_array($meta) || empty($meta['mangaId']) || empty($meta['chapterId'])) {
                return null;
            }

            $data = $this->getJson('/api/read/chapter', [
                'mangaId' => (string) $meta['mangaId'],
                'chapterId' => (string) $meta['chapterId'],
            ]);

            $pages = $data['readChapter']['pages'] ?? $data['pages'] ?? null;
            if (! is_array($pages) || empty($pages)) {
                return null;
            }

            usort($pages, fn ($a, $b) => ((int) ($a['number'] ?? 0)) <=> ((int) ($b['number'] ?? 0)));

            $out = [];
            foreach ($pages as $index => $page) {
                $path = (string) ($page['image'] ?? '');
                if ($path === '') {
                    continue;
                }

                $directUrl = str_starts_with($path, 'http')
                    ? $path
                    : self::CDN.'/'.ltrim($path, '/');
                $proxyUrl = 'https://wsrv.nl/?url='.urlencode($directUrl).'&n=-1';

                $out[] = [
                    'page' => $index + 1,
                    'directUrl' => $directUrl,
                    'saverUrl' => $directUrl,
                    'proxyUrl' => $proxyUrl,
                    'proxySaverUrl' => $proxyUrl,
                ];
            }

            if (empty($out)) {
                return null;
            }

            return [
                'chapterId' => $chapterId,
                'count' => count($out),
                'pages' => $out,
            ];
        });
    }

    /**
     * Pick the closest Atsumaru series for our title.
     *
     * @param  array<int, string>  $altTitles
     * @return array<string, mixed>|null
     */
    protected function findManga(string $title, array $altTitles): ?array
    {
        $normalise = fn (string $value) => strtolower(trim((string) preg_replace('/[^a-z0-9]/', '', $value)));

        $targets = array_values(array_filter(array_map($normalise, array_merge([$title], $altTitles))));
        if (empty($targets)) {
            return null;
        }

        $queries = array_slice(array_values(array_unique(array_filter(
            array_merge([$title], $altTitles),
            fn ($value) => trim((string) $value) !== ''
        ))), 0, 2);

        $best = null;
        $bestScore = 0.0;

        foreach ($queries as $query) {
            $data = $this->getJson('/api/search/manga', [
                'q' => (string) $query,
                'query_by' => 'title',
            ]);
            $hits = $data['hits'] ?? [];
            if (! is_array($hits)) {
                continue;
            }

            foreach ($hits as $hit) {
                $document = $hit['document'] ?? null;
                if (! is_array($document) || empty($document['id'])) {
                    continue;
                }

                $names = [$document['title'] ?? ''];
                foreach ((array) ($document['otherNames'] ?? []) as $name) {
                    $names[] = $name;
                }

                foreach ($names as $name) {
                    $candidate = $normalise((string) $name);
                    if ($candidate === '') {
                        continue;
                    }

                    foreach ($targets as $target) {
                        if ($candidate === $target) {
                            return $document;
                        }

                        similar_text($target, $candidate, $percent);
                        if ($percent > $bestScore) {
                            $bestScore = $percent;
                            $best = $document;
                        }
                    }
                }
            }
        }

        return $bestScore >= 80 ? $best : null;
    }

    /** @return array<string, mixed>|null */
    protected function getJson(string $path, array $query = [], int $timeout = 6): ?array
    {
        $url = $this->base.$path;
        if (! empty($query)) {
            $url .= '?'.http_build_query($query);
        }

        // Cloudflare fingerprints the TLS handshake: Guzzle/Http gets a 403
        // challenge while a plain curl request with a browser UA is served
        // normally. Use curl directly, like the other scrape providers.
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_USERAGENT, self::UA);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json, text/plain;q=0.9,*/*;q=0.8',
            'Referer: '.$this->base.'/',
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 400) {
            Log::debug('Atsumaru request failed', ['path' => $path, 'status' => $code]);

            return null;
        }

        $data = json_decode((string) $body, true);

        return is_array($data) ? $data : null;
    }

    protected function publishedAt(mixed $createdAt): string
    {
        if (! is_numeric($createdAt)) {
            return 'Atsumaru';
        }

        $seconds = (int) $createdAt > 100000000000 ? (int) ($createdAt / 1000) : (int) $createdAt;

        return $seconds > 0 ? date('Y-m-d', $seconds) : 'Atsumaru';
    }
}
