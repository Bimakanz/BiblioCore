<?php

namespace App\Services\Manga\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MultiSourceProvider
{
    protected array $dnsResolves = [
        'weebcentral.com:443:104.21.32.126',
        'www.mangahere.cc:443:104.21.92.224',
        'mangahere.cc:443:104.21.92.224',
        'bacakomik.my:443:104.21.32.127',
        'bacakomik.my:443:172.67.151.249',
        'westmanga.site:443:104.21.61.129',
        'westmanga.site:443:172.67.210.136',
    ];

    /**
     * Resilient HTTP GET request with DoH resolution and fallback.
     */
    protected function request(string $url, int $timeout = 2): ?string
    {
        $isVercel = isset($_ENV['VERCEL']) || isset($_SERVER['VERCEL']) || env('VERCEL');

        $execute = function (bool $useResolve) use ($url, $timeout): ?string {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            if ($useResolve && !empty($this->dnsResolves)) {
                curl_setopt($ch, CURLOPT_RESOLVE, $this->dnsResolves);
            }
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
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
     * Get English chapters from WeebCentral & MangaHere.
     */
    public function getEnglishChapters(string $title, array $altTitles = []): array
    {
        $cacheKey = 'multisrc_en_' . md5($title);

        $result = Cache::remember($cacheKey, 43200, function () use ($title, $altTitles) {
            $normalize = fn(string $s) => strtolower(trim(preg_replace('/[^a-z0-9]/', '', $s)));
            $targetNorms = array_values(array_filter(array_map($normalize, array_merge([$title], $altTitles))));

            $chapters = [];

            try {
                // Wave 1 — search both sites concurrently.
                $weebSearchUrl = 'https://weebcentral.com/search/data?text='.urlencode($title);
                $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $title)));
                $hereSearchUrl = 'https://www.mangahere.cc/search?title='.urlencode($slug);

                $searchResponses = \App\Services\Manga\Support\HttpFetcher::getMany(
                    [$weebSearchUrl, $hereSearchUrl],
                    ['resolve' => $this->dnsResolves]
                );

                $weebHtml = $searchResponses[$weebSearchUrl] ?? null;
                $hereHtml = $searchResponses[$hereSearchUrl] ?? null;

                $bestSeriesId = null;
                $weebScore = 0;
                if ($weebHtml) {
                    preg_match_all('/<a[^>]+href="(https:\/\/weebcentral\.com\/series\/([A-Z0-9]+)\/([a-zA-Z0-9\-]+))"[^>]*>(.*?)<\/a>/is', $weebHtml, $matches, PREG_SET_ORDER);
                    foreach ($matches as $m) {
                        $sId = $m[2];
                        $sNorm = $normalize($m[3]);

                        foreach ($targetNorms as $tNorm) {
                            if ($tNorm === $sNorm) {
                                $bestSeriesId = $sId;
                                $weebScore = 100;
                                break 2;
                            }
                            similar_text($tNorm, $sNorm, $pct);
                            if ($pct > $weebScore) {
                                $weebScore = $pct;
                                $bestSeriesId = $sId;
                            }
                        }
                    }
                }

                $bestPath = null;
                $hereScore = 0;
                if ($hereHtml) {
                    preg_match_all('/<a[^>]+href="(\/manga\/([^"\/]+)\/)"[^>]*title="([^"]+)"/is', $hereHtml, $hMatches, PREG_SET_ORDER);
                    foreach ($hMatches as $hm) {
                        $hNorm = $normalize(trim($hm[3]));
                        foreach ($targetNorms as $tNorm) {
                            if ($tNorm === $hNorm) {
                                $bestPath = $hm[1];
                                $hereScore = 100;
                                break 2;
                            }
                            similar_text($tNorm, $hNorm, $pct);
                            if ($pct > $hereScore) {
                                $hereScore = $pct;
                                $bestPath = $hm[1];
                            }
                        }
                    }
                }

                // Wave 2 — detail pages concurrently.
                $weebListUrl = ($bestSeriesId && $weebScore >= 70)
                    ? "https://weebcentral.com/series/{$bestSeriesId}/full-chapter-list"
                    : null;
                $hereDetailUrl = ($bestPath && $hereScore >= 70)
                    ? "https://www.mangahere.cc{$bestPath}"
                    : null;

                $detailResponses = \App\Services\Manga\Support\HttpFetcher::getMany(
                    array_values(array_filter([$weebListUrl, $hereDetailUrl])),
                    ['resolve' => $this->dnsResolves]
                );

                $listHtml = $weebListUrl ? ($detailResponses[$weebListUrl] ?? null) : null;
                if ($listHtml) {
                    preg_match_all('/<a[^>]+href="(https:\/\/weebcentral\.com\/chapters\/([A-Z0-9]+))"[^>]*>(.*?)<\/a>/is', $listHtml, $chapMatches, PREG_SET_ORDER);
                    foreach ($chapMatches as $cm) {
                        $chapUrl = $cm[1];
                        $cId = $cm[2];
                        $cText = trim(strip_tags($cm[3]));
                        preg_match('/(?:Chapter|Ch\.)\s*([0-9\.]+)/i', $cText, $num);
                        $chNum = ! empty($num[1]) ? (string) $num[1] : '0';

                        Cache::put("weeb_url_{$cId}", $chapUrl, 604800);

                        $chapters[] = [
                            'id' => 'wb_'.$cId,
                            'chapter' => $chNum,
                            'title' => $cText !== "Chapter {$chNum}" ? $cText : '',
                            'language' => 'en',
                            'group' => 'WeebCentral',
                            'provider' => 'WeebCentral',
                            'publishAt' => 'WeebCentral Archive',
                            'externalUrl' => null,
                            'readPath' => $chapUrl,
                        ];
                    }
                }

                if (count($chapters) < 5 && $hereDetailUrl) {
                    $detailHtml = $detailResponses[$hereDetailUrl] ?? null;
                    if ($detailHtml) {
                        preg_match_all('/<a[^>]+href="(\/manga\/[^"]+\.html)"[^>]*title="([^"]+)"/is', $detailHtml, $chapMatches, PREG_SET_ORDER);
                        foreach ($chapMatches as $cm) {
                            $cUrl = 'https://www.mangahere.cc'.$cm[1];
                            $cTitle = trim($cm[2]);
                            preg_match('/(?:Chapter|Ch\.)\s*([0-9\.]+)/i', $cTitle, $num);
                            $chNum = ! empty($num[1]) ? (string) $num[1] : '0';

                            $cid = 'mh_'.md5($cUrl);
                            Cache::put("mh_url_{$cid}", $cUrl, 604800);

                            $chapters[] = [
                                'id' => $cid,
                                'chapter' => $chNum,
                                'title' => $cTitle,
                                'language' => 'en',
                                'group' => 'MangaHere',
                                'provider' => 'MangaHere',
                                'publishAt' => 'MangaHere Archive',
                                'externalUrl' => null,
                                'readPath' => $cUrl,
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('MultiSource EN fetch error: '.$e->getMessage());
            }

            return $chapters;
        });

        if (empty($result)) {
            Cache::forget($cacheKey);
        }

        return $result;
    }

    /**
     * Get Indonesian chapters from WestManga, Bacakomik, Shinigami ID.
     */
    public function getIndoChapters(string $title, array $altTitles = []): array
    {
        $cacheKey = 'multisrc_id_' . md5($title);

        $result = Cache::remember($cacheKey, 43200, function () use ($title, $altTitles) {
            $clean = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $title)));
            $slug = preg_replace('/\s+/', '-', $clean);

            $candidates = [$slug];
            foreach ($altTitles as $alt) {
                $cAlt = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $alt)));
                if (!empty($cAlt)) {
                    $candidates[] = preg_replace('/\s+/', '-', $cAlt);
                }
            }

            // Build every candidate URL up-front and fetch the whole discovery
            // wave concurrently instead of walking provider-by-provider.
            $bacakomikUrls = [];
            $westMangaUrls = [];
            $shinigamiUrls = [];
            foreach ($candidates as $cand) {
                $bacakomikUrls[] = "https://bacakomik.my/komik/{$cand}/";
                $westMangaUrls[] = "https://westmanga.site/{$cand}/";
                $shinigamiUrls[] = "https://shinigami03.com/series/{$cand}/";
            }

            $responses = \App\Services\Manga\Support\HttpFetcher::getMany(
                array_merge($bacakomikUrls, $westMangaUrls, $shinigamiUrls),
                ['resolve' => $this->dnsResolves]
            );

            $sources = [
                ['urls' => $bacakomikUrls, 'prefix' => 'bk', 'group' => 'Bacakomik', 'publishAt' => 'Bacakomik Indo'],
                ['urls' => $westMangaUrls, 'prefix' => 'wm', 'group' => 'WestManga', 'publishAt' => 'WestManga Indo'],
                ['urls' => $shinigamiUrls, 'prefix' => 'shn', 'group' => 'Shinigami ID', 'publishAt' => 'Shinigami Indo'],
            ];

            foreach ($sources as $source) {
                foreach ($source['urls'] as $url) {
                    $html = $responses[$url] ?? null;
                    if (! $html || ! str_contains($html, 'chapter')) {
                        continue;
                    }

                    $found = $this->extractChapterLinks($html, $source['prefix'], $source['group'], $source['publishAt']);
                    if (! empty($found)) {
                        return $found;
                    }
                }
            }

            return [];
        });

        if (empty($result)) {
            Cache::forget($cacheKey);
        }

        return $result;
    }

    /**
     * Parse a generic manga detail page into the shared chapter structure.
     */
    protected function extractChapterLinks(string $html, string $prefix, string $group, string $publishAt): array
    {
        preg_match_all('/<a[^>]+href="([^"]+chapter-[0-9\.]+[^"]*)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);

        $chapters = [];
        foreach ($matches as $m) {
            $chapUrl = $m[1];
            $text = trim(strip_tags($m[2]));
            preg_match('/(?:Chapter|Ch\.)\s*([0-9\.]+)/i', $text, $num);
            $chNum = ! empty($num[1]) ? (string) $num[1] : '0';

            $cid = $prefix.'_'.md5($chapUrl);
            Cache::put("{$prefix}_url_{$cid}", $chapUrl, 604800);

            $chapters[] = [
                'id' => $cid,
                'chapter' => $chNum,
                'title' => $text,
                'language' => 'id',
                'group' => $group,
                'provider' => $group,
                'publishAt' => $publishAt,
                'externalUrl' => null,
                'readPath' => $chapUrl,
            ];
        }

        return $chapters;
    }

    /**
     * Get chapter pages for WeebCentral, MangaHere, WestManga, Bacakomik, Shinigami ID.
     */
    public function getChapterPages(string $chapterId): ?array
    {
        $cacheKey = 'multisrc_pages_' . md5($chapterId);

        return Cache::remember($cacheKey, 604800, function () use ($chapterId) {
            $prefix = explode('_', $chapterId)[0] ?? '';
            $url = null;

            if ($prefix === 'wb') {
                $subId = str_replace('wb_', '', $chapterId);
                $url = Cache::get("weeb_url_{$subId}") ?: "https://weebcentral.com/chapters/{$subId}";
            } elseif ($prefix === 'mh') {
                $url = Cache::get("mh_url_{$chapterId}");
            } elseif ($prefix === 'wm') {
                $url = Cache::get("wm_url_{$chapterId}");
            } elseif ($prefix === 'bk') {
                $url = Cache::get("bk_url_{$chapterId}");
            } elseif ($prefix === 'shn') {
                $url = Cache::get("shn_url_{$chapterId}");
            }

            if (!$url) {
                return null;
            }

            $html = $this->request($url);
            if (!$html) {
                return null;
            }

            // Extract all image sources
            preg_match_all('/<img[^>]+(?:data-src|src)="([^"]+)"/is', $html, $imgs);
            if (empty($imgs[1])) {
                return null;
            }

            $validImgs = [];
            foreach ($imgs[1] as $imgUrl) {
                if (preg_match('/\.(jpg|jpeg|png|webp)/i', $imgUrl) && 
                    !str_contains($imgUrl, 'logo') && 
                    !str_contains($imgUrl, 'icon') && 
                    !str_contains($imgUrl, 'Ikon') && 
                    !str_contains($imgUrl, 'iklan') && 
                    !str_contains($imgUrl, 'donate') && 
                    !str_contains($imgUrl, 'promosi')) {
                    $validImgs[] = $imgUrl;
                }
            }

            if (empty($validImgs)) {
                return null;
            }

            $pages = [];
            foreach ($validImgs as $idx => $directUrl) {
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

