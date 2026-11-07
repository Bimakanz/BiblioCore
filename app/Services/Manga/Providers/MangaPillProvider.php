<?php

namespace App\Services\Manga\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MangaPillProvider
{
    protected string $baseUrl = 'https://mangapill.com';
    protected array $dnsResolves = [
        'mangapill.com:443:104.21.5.54',
        'mangapill.com:443:172.67.135.215',
    ];

    /**
     * Request helper with DoH DNS bypass.
     */
    protected function request(string $url): ?string
    {
        $isVercel = isset($_ENV['VERCEL']) || isset($_SERVER['VERCEL']) || env('VERCEL');

        $execute = function (bool $useResolve) use ($url): ?string {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2.5);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            if ($useResolve && !empty($this->dnsResolves)) {
                curl_setopt($ch, CURLOPT_RESOLVE, $this->dnsResolves);
            }
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
            curl_setopt($ch, CURLOPT_REFERER, 'https://mangapill.com/');
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
     * Fetch English chapters from MangaPill with strict title matching.
     */
    public function getChapters(string $title, array $altTitles = []): array
    {
        $cacheKey = 'mangapill_ch_v2_' . md5($title);

        return Cache::remember($cacheKey, 3600, function () use ($title, $altTitles) {
            $normalize = fn(string $s) => strtolower(trim(preg_replace('/[^a-z0-9]/', '', $s)));
            $targetNorms = array_values(array_filter(array_map($normalize, array_merge([$title], $altTitles))));

            $searchQueries = [$title];
            foreach (array_slice($altTitles, 0, 3) as $alt) {
                $searchQueries[] = $alt;
            }

            $candidateList = [];
            foreach ($searchQueries as $q) {
                $cleanQ = trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $q));
                if (empty($cleanQ)) continue;

                $searchUrl = "{$this->baseUrl}/search?q=" . urlencode($cleanQ);
                $html = $this->request($searchUrl);
                if (!$html) continue;

                preg_match_all('/<a[^>]+href="(\/manga\/\d+\/([^"]+))"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
                foreach ($matches as $m) {
                    $mPath = $m[1];
                    $mSlug = $m[2];
                    $mText = trim(strip_tags($m[3]));

                    $bestScore = 0;
                    $slugNorm = $normalize($mSlug);
                    $textNorm = $normalize($mText);

                    foreach ($targetNorms as $tNorm) {
                        if ($tNorm === $slugNorm || $tNorm === $textNorm) {
                            $bestScore = max($bestScore, 100);
                            break;
                        }
                        similar_text($tNorm, $slugNorm, $pctSlug);
                        similar_text($tNorm, $textNorm, $pctText);
                        $bestScore = max($bestScore, $pctSlug, $pctText);
                    }

                    // Only candidates with at least 70% similarity are accepted
                    if ($bestScore >= 70) {
                        $candidateList[$mPath] = max($candidateList[$mPath] ?? 0, $bestScore);
                    }
                }
            }

            if (empty($candidateList)) {
                return [];
            }

            // Sort candidates by highest similarity score first
            arsort($candidateList);

            $bestChapters = [];
            $highestScore = reset($candidateList);

            foreach ($candidateList as $mangaPath => $score) {
                // If candidate is substantially worse than best match, skip
                if ($score < ($highestScore - 15)) {
                    continue;
                }

                $url = "{$this->baseUrl}{$mangaPath}";
                $html = $this->request($url);
                if (!$html) continue;

                preg_match_all('/<a[^>]+href="(\/chapters\/([0-9\-]+)\/[^"]+)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
                if (!empty($matches)) {
                    $chapters = [];
                    foreach ($matches as $m) {
                        $path = $m[1];
                        $rawText = trim(strip_tags($m[3]));

                        preg_match('/(?:Chapter|Ch\.)\s*([0-9\.]+)/i', $rawText, $chNum);
                        $num = !empty($chNum[1]) ? (string)$chNum[1] : '0';

                        $cleanPath = trim(str_replace('/', '__', $path), '_');
                        $chapters[] = [
                            'id' => 'mp_' . $cleanPath,
                            'chapter' => $num,
                            'title' => $rawText !== "Chapter {$num}" ? $rawText : '',
                            'language' => 'en',
                            'group' => 'MangaPill Scan',
                            'provider' => 'MangaPill',
                            'publishAt' => 'English Archive',
                            'externalUrl' => null,
                            'readPath' => $path,
                        ];
                    }

                    if ($score >= 90) {
                        return $chapters;
                    }

                    if (count($chapters) > count($bestChapters)) {
                        $bestChapters = $chapters;
                    }
                }
            }

            return $bestChapters;
        });
    }

    /**
     * Fetch chapter images from MangaPill.
     */
    public function getChapterPages(string $chapterId): ?array
    {
        $cacheKey = 'mangapill_pages_' . md5($chapterId);

        return Cache::remember($cacheKey, 600, function () use ($chapterId) {
            $path = '/' . str_replace('__', '/', str_replace('mp_', '', $chapterId));
            $url = "{$this->baseUrl}{$path}";

            $html = $this->request($url);
            if (!$html) {
                return null;
            }

            // Extract image URLs
            preg_match_all('/<img[^>]+data-src="([^"]+)"/is', $html, $imgs);
            if (empty($imgs[1])) {
                preg_match_all('/<img[^>]+src="(https:\/\/cdn\.[^"]+)"/is', $html, $imgs);
            }

            if (empty($imgs[1])) {
                return null;
            }

            $pages = [];
            foreach ($imgs[1] as $idx => $directUrl) {
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
