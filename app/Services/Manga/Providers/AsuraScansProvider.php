<?php

namespace App\Services\Manga\Providers;

use App\Services\Manga\Support\ChapterLabel;
use App\Services\Manga\Support\HttpFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Asura Scans (manhwa/manhua aggregator).
 *
 * Uses the public JSON API for discovery and chapter lists, then reads the
 * page image paths that the chapter page embeds. Only free chapters are
 * listed: premium chapters have no readable images without an account, so
 * exposing them would create the same "chapter exists but has no panels"
 * problem this provider is meant to solve.
 */
class AsuraScansProvider
{
    protected string $apiBase = 'https://api.asurascans.com/api';

    protected string $siteBase = 'https://asurascans.com';

    protected const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /**
     * English chapter list for a title.
     */
    public function getChapters(string $title, array $altTitles = []): array
    {
        $cacheKey = 'asura_chapters_v1_'.md5(strtolower(trim($title)));

        $result = Cache::remember($cacheKey, 43200, function () use ($title, $altTitles) {
            $chapters = [];

            try {
                $series = $this->findSeries($title, $altTitles);
                if (! $series) {
                    return [];
                }

                $publicUrl = $series['public_url'] ?? null;
                if (! $publicUrl) {
                    $detail = $this->getJson($this->apiBase.'/series/'.rawurlencode($series['slug']));
                    $publicUrl = $detail['series']['public_url'] ?? null;
                }
                if (! $publicUrl) {
                    return [];
                }

                $publicUrl = '/'.ltrim((string) $publicUrl, '/');
                $slug = (string) $series['slug'];

                $payload = $this->getJson($this->apiBase.'/series/'.rawurlencode($slug).'/chapters');
                $list = $payload['data'] ?? null;
                if (! is_array($list)) {
                    return [];
                }

                foreach ($list as $chapter) {
                    if (! is_array($chapter) || ! empty($chapter['is_premium'])) {
                        continue;
                    }

                    $number = $chapter['number'] ?? null;
                    if ($number === null) {
                        continue;
                    }

                    $number = ChapterLabel::number((string) $number, 0);
                    $chapterId = 'as_'.($chapter['id'] ?? md5($slug.'|'.$number));
                    $readUrl = $this->siteBase.$publicUrl.'/chapter/'.rawurlencode((string) $chapter['number']);

                    Cache::put('asura_meta_'.$chapterId, [
                        'url' => $readUrl,
                        'number' => (string) $chapter['number'],
                        'series' => $slug,
                    ], 604800);

                    $chapters[] = [
                        'id' => $chapterId,
                        'chapter' => $number,
                        'title' => '',
                        'language' => 'en',
                        'group' => 'Asura Scans',
                        'provider' => 'Asura Scans',
                        'publishAt' => $chapter['published_at'] ?? 'Asura Scans',
                        'externalUrl' => null,
                        'readPath' => $readUrl,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('Asura Scans chapter fetch error: '.$e->getMessage());
            }

            return $chapters;
        });

        if (empty($result)) {
            Cache::forget($cacheKey);
        }

        return $result;
    }

    /**
     * Readable pages for one Asura chapter.
     */
    public function getChapterPages(string $chapterId): ?array
    {
        $cacheKey = 'asura_pages_'.md5($chapterId);

        return Cache::remember($cacheKey, 604800, function () use ($chapterId) {
            $meta = Cache::get('asura_meta_'.$chapterId);
            if (! is_array($meta) || empty($meta['url'])) {
                return null;
            }

            $html = $this->request((string) $meta['url'], 8);
            if (! $html) {
                return null;
            }

            $needle = '/chapters/'.$meta['series'].'/'.$meta['number'].'/';
            preg_match_all('~asura-images/chapters/[^"\'\\\\<>\s&]+\.(?:webp|jpe?g|png)(?:\?[^"\'\\\\<>\s&]*)?~i', $html, $matches);

            $urls = [];
            foreach ($matches[0] ?? [] as $path) {
                $path = html_entity_decode((string) $path, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (str_contains($path, $needle)) {
                    $urls[] = 'https://cdn.asurascans.com/'.ltrim($path, '/');
                }
            }

            $urls = array_values(array_unique($urls));
            sort($urls, SORT_NATURAL);
            if (empty($urls)) {
                return null;
            }

            $pages = [];
            foreach ($urls as $index => $directUrl) {
                $proxyUrl = 'https://wsrv.nl/?url='.urlencode($directUrl).'&n=-1';
                $pages[] = [
                    'page' => $index + 1,
                    'directUrl' => $directUrl,
                    'saverUrl' => $directUrl,
                    'proxyUrl' => $proxyUrl,
                    'proxySaverUrl' => $proxyUrl,
                ];
            }

            return [
                'chapterId' => $chapterId,
                'pages' => $pages,
            ];
        });
    }

    /**
     * Find the closest series returned by the Asura search endpoint.
     */
    protected function findSeries(string $title, array $altTitles): ?array
    {
        $normalise = fn (string $value) => strtolower(trim((string) preg_replace('/[^a-z0-9]/', '', $value)));

        $targets = array_values(array_filter(array_map($normalise, array_merge([$title], $altTitles))));
        if (empty($targets)) {
            return null;
        }

        $queries = array_slice(array_merge([$title], $altTitles), 0, 3);
        $urls = [];
        foreach ($queries as $query) {
            if (trim((string) $query) !== '') {
                $urls[] = $this->apiBase.'/series?search='.urlencode((string) $query);
            }
        }

        $responses = HttpFetcher::getMany(array_values(array_unique($urls)), [
            'timeout' => 4,
            'connect_timeout' => 2,
            'headers' => ['Accept: application/json'],
        ]);

        $best = null;
        $bestScore = 0;

        foreach ($responses as $body) {
            if (! $body) {
                continue;
            }
            $payload = json_decode($body, true);
            $items = $payload['data'] ?? [];
            if (! is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (! is_array($item) || empty($item['slug'])) {
                    continue;
                }

                $names = array_merge(
                    [(string) ($item['title'] ?? '')],
                    is_array($item['alt_titles'] ?? null) ? $item['alt_titles'] : []
                );

                foreach ($names as $name) {
                    $candidate = $normalise((string) $name);
                    if ($candidate === '') {
                        continue;
                    }
                    foreach ($targets as $target) {
                        if ($candidate === $target) {
                            return $item;
                        }
                        similar_text($target, $candidate, $percent);
                        if ($percent > $bestScore) {
                            $bestScore = $percent;
                            $best = $item;
                        }
                    }
                }
            }
        }

        return $bestScore >= 70 ? $best : null;
    }

    protected function getJson(string $url): ?array
    {
        $body = $this->request($url, 5);
        if (! $body) {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function request(string $url, int $timeout = 5): ?string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_USERAGENT, self::UA);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json, text/html;q=0.9,*/*;q=0.8',
            'Referer: '.$this->siteBase.'/',
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $code < 200 || $code >= 400) {
            return null;
        }

        return $body;
    }
}
