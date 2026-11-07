<?php

namespace App\Services\Manga\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MangaNatoProvider
{
    protected string $baseUrl = 'https://readmanganato.com';
    protected array $dnsResolves = [
        'readmanganato.com:443:162.4.84.255',
        'readmanganato.com:443:162.4.84.215',
    ];

    /**
     * Request with DNS resolve.
     */
    protected function request(string $url): ?string
    {
        $isVercel = isset($_ENV['VERCEL']) || isset($_SERVER['VERCEL']) || env('VERCEL');

        $execute = function (bool $useResolve) use ($url): ?string {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2.5);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            if ($useResolve && !empty($this->dnsResolves)) {
                curl_setopt($ch, CURLOPT_RESOLVE, $this->dnsResolves);
            }
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
            curl_setopt($ch, CURLOPT_REFERER, 'https://readmanganato.com/');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($res !== false && $httpCode >= 200 && $httpCode < 400) {
                return $res;
            }
            return null;
        };

        if ($isVercel) {
            return $execute(false) ?? $execute(true);
        }

        return $execute(true) ?? $execute(false);
    }

    /**
     * Search and get chapters from MangaNato with strict title matching.
     */
    public function getChapters(string $title, array $altTitles = []): array
    {
        $cacheKey = 'manganato_ch_v2_' . md5($title);

        $result = Cache::remember($cacheKey, 43200, function () use ($title, $altTitles) {
            $normalize = fn(string $s) => strtolower(trim(preg_replace('/[^a-z0-9]/', '', $s)));
            $targetNorms = array_values(array_filter(array_map($normalize, array_merge([$title], $altTitles))));

            $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $title)));
            $slug = preg_replace('/\s+/', '_', $slug);

            $searchUrl = "https://readmanganato.com/search/story/" . urlencode($slug);
            $html = $this->request($searchUrl);
            if (!$html) {
                return [];
            }

            // Find candidate search results
            preg_match_all('/<a[^>]+class="item-img[^"]*"[^>]+href="([^"]+)"[^>]+title="([^"]+)"/i', $html, $matches, PREG_SET_ORDER);
            if (empty($matches)) {
                preg_match_all('/<a[^>]+class="item-title[^"]*"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
            }

            $bestUrl = null;
            $bestScore = 0;

            foreach ($matches as $m) {
                $candUrl = $m[1];
                $candTitle = trim(strip_tags($m[2]));
                $candNorm = $normalize($candTitle);

                foreach ($targetNorms as $tNorm) {
                    if ($tNorm === $candNorm) {
                        $bestScore = 100;
                        $bestUrl = $candUrl;
                        break 2;
                    }
                    similar_text($tNorm, $candNorm, $pct);
                    if ($pct > $bestScore) {
                        $bestScore = $pct;
                        $bestUrl = $candUrl;
                    }
                }
            }

            // Reject if similarity is below 70%
            if (!$bestUrl || $bestScore < 70) {
                return [];
            }

            $detailHtml = $this->request($bestUrl);
            if (!$detailHtml) {
                return [];
            }

            preg_match_all('/<a[^>]+class="chapter-name[^"]*"[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $detailHtml, $matches, PREG_SET_ORDER);

            $chapters = [];
            foreach ($matches as $m) {
                $chapUrl = $m[1];
                $name = trim(strip_tags($m[2]));
                preg_match('/(?:Chapter|Ch\.)\s*([0-9\.]+)/i', $name, $num);
                $chapterNum = !empty($num[1]) ? (string)$num[1] : '0';

                $chapId = 'nato_' . md5($chapUrl);
                Cache::put("nato_url_{$chapId}", $chapUrl, 604800);

                $chapters[] = [
                    'id' => $chapId,
                    'chapter' => $chapterNum,
                    'title' => $name,
                    'language' => 'en',
                    'group' => 'MangaNato Network',
                    'provider' => 'MangaNato',
                    'publishAt' => 'MangaNato CDN',
                    'externalUrl' => null,
                    'readPath' => $chapUrl,
                ];
            }

            return $chapters;
        });

        if (empty($result)) {
            Cache::forget($cacheKey);
        }

        return $result;
    }

    /**
     * Get chapter pages.
     */
    public function getChapterPages(string $chapterId): ?array
    {
        $chapUrl = Cache::get("nato_url_{$chapterId}");
        if (!$chapUrl) {
            return null;
        }

        $cacheKey = 'nato_pages_' . md5($chapterId);
        return Cache::remember($cacheKey, 604800, function () use ($chapUrl, $chapterId) {
            $html = $this->request($chapUrl);
            if (!$html) {
                return null;
            }

            preg_match_all('/<div class="container-chapter-reader"[^>]*>(.*?)<\/div>/is', $html, $container);
            $targetHtml = !empty($container[1][0]) ? $container[1][0] : $html;

            preg_match_all('/<img[^>]+src="([^"]+)"/is', $targetHtml, $imgs);
            if (empty($imgs[1])) {
                return null;
            }

            $pages = [];
            foreach ($imgs[1] as $idx => $directUrl) {
                $proxyUrl = 'https://wsrv.nl/?url=' . urlencode($directUrl) . '&n=-1';
                $pages[] = [
                    'page' => $idx + 1,
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
}

