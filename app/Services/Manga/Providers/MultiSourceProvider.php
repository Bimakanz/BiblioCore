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
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
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

        return Cache::remember($cacheKey, 3600, function () use ($title, $altTitles) {
            $normalize = fn(string $s) => strtolower(trim(preg_replace('/[^a-z0-9]/', '', $s)));
            $targetNorms = array_values(array_filter(array_map($normalize, array_merge([$title], $altTitles))));

            $chapters = [];

            // 1. WeebCentral
            try {
                $weebHtml = $this->request("https://weebcentral.com/search/data?text=" . urlencode($title));
                if ($weebHtml) {
                    preg_match_all('/<a[^>]+href="(https:\/\/weebcentral\.com\/series\/([A-Z0-9]+)\/([a-zA-Z0-9\-]+))"[^>]*>(.*?)<\/a>/is', $weebHtml, $matches, PREG_SET_ORDER);
                    
                    $bestSeriesId = null;
                    $bestScore = 0;
                    foreach ($matches as $m) {
                        $sId = $m[2];
                        $sSlug = $m[3];
                        $sNorm = $normalize($sSlug);

                        foreach ($targetNorms as $tNorm) {
                            if ($tNorm === $sNorm) {
                                $bestSeriesId = $sId;
                                $bestScore = 100;
                                break 2;
                            }
                            similar_text($tNorm, $sNorm, $pct);
                            if ($pct > $bestScore) {
                                $bestScore = $pct;
                                $bestSeriesId = $sId;
                            }
                        }
                    }

                    if ($bestSeriesId && $bestScore >= 70) {
                        $listHtml = $this->request("https://weebcentral.com/series/{$bestSeriesId}/full-chapter-list");
                        if ($listHtml) {
                            preg_match_all('/<a[^>]+href="(https:\/\/weebcentral\.com\/chapters\/([A-Z0-9]+))"[^>]*>(.*?)<\/a>/is', $listHtml, $chapMatches, PREG_SET_ORDER);
                            foreach ($chapMatches as $cm) {
                                $chapUrl = $cm[1];
                                $cId = $cm[2];
                                $cText = trim(strip_tags($cm[3]));
                                preg_match('/(?:Chapter|Ch\.)\s*([0-9\.]+)/i', $cText, $num);
                                $chNum = !empty($num[1]) ? (string)$num[1] : '0';

                                Cache::put("weeb_url_{$cId}", $chapUrl, 86400);

                                $chapters[] = [
                                    'id' => 'wb_' . $cId,
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
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("WeebCentral fetch error: " . $e->getMessage());
            }

            // 2. MangaHere
            if (count($chapters) < 5) {
                try {
                    $slug = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $title)));
                    $searchUrl = "https://www.mangahere.cc/search?title=" . urlencode($slug);
                    $hereHtml = $this->request($searchUrl);
                    if ($hereHtml) {
                        preg_match_all('/<a[^>]+href="(\/manga\/([^"\/]+)\/)"[^>]*title="([^"]+)"/is', $hereHtml, $hMatches, PREG_SET_ORDER);
                        $bestPath = null;
                        $bestScore = 0;
                        foreach ($hMatches as $hm) {
                            $hTitle = trim($hm[3]);
                            $hNorm = $normalize($hTitle);
                            foreach ($targetNorms as $tNorm) {
                                if ($tNorm === $hNorm) {
                                    $bestPath = $hm[1];
                                    $bestScore = 100;
                                    break 2;
                                }
                                similar_text($tNorm, $hNorm, $pct);
                                if ($pct > $bestScore) {
                                    $bestScore = $pct;
                                    $bestPath = $hm[1];
                                }
                            }
                        }

                        if ($bestPath && $bestScore >= 70) {
                            $detailHtml = $this->request("https://www.mangahere.cc{$bestPath}");
                            if ($detailHtml) {
                                preg_match_all('/<a[^>]+href="(\/manga\/[^"]+\.html)"[^>]*title="([^"]+)"/is', $detailHtml, $chapMatches, PREG_SET_ORDER);
                                foreach ($chapMatches as $cm) {
                                    $cUrl = "https://www.mangahere.cc" . $cm[1];
                                    $cTitle = trim($cm[2]);
                                    preg_match('/(?:Chapter|Ch\.)\s*([0-9\.]+)/i', $cTitle, $num);
                                    $chNum = !empty($num[1]) ? (string)$num[1] : '0';

                                    $cid = 'mh_' . md5($cUrl);
                                    Cache::put("mh_url_{$cid}", $cUrl, 86400);

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
                    }
                } catch (\Throwable $e) {
                    Log::warning("MangaHere fetch error: " . $e->getMessage());
                }
            }

            return $chapters;
        });
    }

    /**
     * Get Indonesian chapters from WestManga, Bacakomik, Shinigami ID.
     */
    public function getIndoChapters(string $title, array $altTitles = []): array
    {
        $cacheKey = 'multisrc_id_' . md5($title);

        return Cache::remember($cacheKey, 3600, function () use ($title, $altTitles) {
            $clean = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $title)));
            $slug = preg_replace('/\s+/', '-', $clean);

            $candidates = [$slug];
            foreach ($altTitles as $alt) {
                $cAlt = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $alt)));
                if (!empty($cAlt)) {
                    $candidates[] = preg_replace('/\s+/', '-', $cAlt);
                }
            }

            $chapters = [];

            // 1. Bacakomik (Primary Indonesian Scanlation Archive)
            foreach ($candidates as $cand) {
                try {
                    $url = "https://bacakomik.my/komik/{$cand}/";
                    $html = $this->request($url);
                    if ($html && str_contains($html, 'chapter')) {
                        preg_match_all('/<a[^>]+href="([^"]+chapter-[0-9\.]+[^"]*)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
                        foreach ($matches as $m) {
                            $chapUrl = $m[1];
                            $text = trim(strip_tags($m[2]));
                            preg_match('/(?:Chapter|Ch\.)\s*([0-9\.]+)/i', $text, $num);
                            $chNum = !empty($num[1]) ? (string)$num[1] : '0';

                            $cid = 'bk_' . md5($chapUrl);
                            Cache::put("bk_url_{$cid}", $chapUrl, 86400);

                            $chapters[] = [
                                'id' => $cid,
                                'chapter' => $chNum,
                                'title' => $text,
                                'language' => 'id',
                                'group' => 'Bacakomik',
                                'provider' => 'Bacakomik',
                                'publishAt' => 'Bacakomik Indo',
                                'externalUrl' => null,
                                'readPath' => $chapUrl,
                            ];
                        }
                        if (!empty($chapters)) break;
                    }
                } catch (\Throwable $e) {}
            }

            // 2. WestManga (Mirror)
            if (empty($chapters)) {
                foreach ($candidates as $cand) {
                    try {
                        $url = "https://westmanga.site/{$cand}/";
                        $html = $this->request($url);
                        if ($html && str_contains($html, 'chapter')) {
                            preg_match_all('/<a[^>]+href="([^"]+chapter-[0-9\.]+[^"]*)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
                            foreach ($matches as $m) {
                                $chapUrl = $m[1];
                                $text = trim(strip_tags($m[2]));
                                preg_match('/(?:Chapter|Ch\.)\s*([0-9\.]+)/i', $text, $num);
                                $chNum = !empty($num[1]) ? (string)$num[1] : '0';

                                $cid = 'wm_' . md5($chapUrl);
                                Cache::put("wm_url_{$cid}", $chapUrl, 86400);

                                $chapters[] = [
                                    'id' => $cid,
                                    'chapter' => $chNum,
                                    'title' => $text,
                                    'language' => 'id',
                                    'group' => 'WestManga',
                                    'provider' => 'WestManga',
                                    'publishAt' => 'WestManga Indo',
                                    'externalUrl' => null,
                                    'readPath' => $chapUrl,
                                ];
                            }
                            if (!empty($chapters)) break;
                        }
                    } catch (\Throwable $e) {}
                }
            }

            // 3. Shinigami ID
            if (empty($chapters)) {
                foreach ($candidates as $cand) {
                    try {
                        $url = "https://shinigami03.com/series/{$cand}/";
                        $html = $this->request($url);
                        if ($html && str_contains($html, 'chapter')) {
                            preg_match_all('/<a[^>]+href="([^"]+\/chapter-[0-9\.]+[^"]*)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
                            foreach ($matches as $m) {
                                $chapUrl = $m[1];
                                $text = trim(strip_tags($m[2]));
                                preg_match('/(?:Chapter|Ch\.)\s*([0-9\.]+)/i', $text, $num);
                                $chNum = !empty($num[1]) ? (string)$num[1] : '0';

                                $cid = 'shn_' . md5($chapUrl);
                                Cache::put("shn_url_{$cid}", $chapUrl, 86400);

                                $chapters[] = [
                                    'id' => $cid,
                                    'chapter' => $chNum,
                                    'title' => $text,
                                    'language' => 'id',
                                    'group' => 'Shinigami ID',
                                    'provider' => 'Shinigami ID',
                                    'publishAt' => 'Shinigami Indo',
                                    'externalUrl' => null,
                                    'readPath' => $chapUrl,
                                ];
                            }
                            if (!empty($chapters)) break;
                        }
                    } catch (\Throwable $e) {}
                }
            }

            return $chapters;
        });
    }

    /**
     * Get chapter pages for WeebCentral, MangaHere, WestManga, Bacakomik, Shinigami ID.
     */
    public function getChapterPages(string $chapterId): ?array
    {
        $cacheKey = 'multisrc_pages_' . md5($chapterId);

        return Cache::remember($cacheKey, 7200, function () use ($chapterId) {
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
                $proxyUrl = "/manga/proxy/image?url=" . urlencode($directUrl);
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

