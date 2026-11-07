<?php

namespace App\Services\Manga\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class KomikIndoProvider
{
    protected string $baseUrl = 'https://komikindo.tv';
    protected array $dnsResolves = [
        'komikindo.tv:443:104.21.76.106',
        'komikindo.tv:443:172.67.193.16',
        'komikindo.ch:443:104.21.76.106',
        'komikindo.moe:443:104.21.76.106',
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
            curl_setopt($ch, CURLOPT_TIMEOUT, 8);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
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
     * Get chapters from KomikIndo.
     */
    public function getChapters(string $title, array $altTitles = []): array
    {
        $cacheKey = 'komikindo_ch_' . md5($title);

        return Cache::remember($cacheKey, 3600, function () use ($title, $altTitles) {
            $clean = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $title)));
            $slug = preg_replace('/\s+/', '-', $clean);

            // Candidate slugs
            $candidates = [$slug];
            if (str_contains($clean, 'komi')) {
                $candidates[] = 'komi-san-wa-komyushou-desu';
            }
            if (str_contains($clean, 'frieren')) {
                $candidates[] = 'sousou-no-frieren';
            }
            if (str_contains($clean, 'solo leveling')) {
                $candidates[] = 'solo-leveling';
            }

            foreach ($candidates as $candidate) {
                $url = "https://komikindo.ch/komik/{$candidate}/";
                $html = $this->request($url);
                if ($html && str_contains($html, 'chapter-list')) {
                    preg_match_all('/<a[^>]+href="([^"]+\/chapter-[0-9\.]+[^"]*)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER);
                    if (!empty($matches)) {
                        $chapters = [];
                        foreach ($matches as $m) {
                            $chapUrl = $m[1];
                            $text = trim(strip_tags($m[2]));
                            preg_match('/(?:Chapter|Ch\.)\s*([0-9\.]+)/i', $text, $num);
                            $chapNum = !empty($num[1]) ? (string)$num[1] : '0';

                            $id = 'kmd_' . md5($chapUrl);
                            Cache::put("kmd_url_{$id}", $chapUrl, 86400);

                            $chapters[] = [
                                'id' => $id,
                                'chapter' => $chapNum,
                                'title' => $text,
                                'language' => 'id',
                                'group' => 'KomikIndo Team',
                                'provider' => 'KomikIndo',
                                'publishAt' => 'KomikIndo Archive',
                                'externalUrl' => null,
                                'readPath' => $chapUrl,
                            ];
                        }
                        return $chapters;
                    }
                }
            }

            return [];
        });
    }

    /**
     * Get chapter pages.
     */
    public function getChapterPages(string $chapterId): ?array
    {
        $chapUrl = Cache::get("kmd_url_{$chapterId}");
        if (!$chapUrl) {
            return null;
        }

        $cacheKey = 'kmd_pages_' . md5($chapterId);
        return Cache::remember($cacheKey, 7200, function () use ($chapUrl, $chapterId) {
            $html = $this->request($chapUrl);
            if (!$html) {
                return null;
            }

            preg_match_all('/<div id="chimg-reh"[^>]*>(.*?)<\/div>/is', $html, $container);
            $target = !empty($container[1][0]) ? $container[1][0] : $html;

            preg_match_all('/<img[^>]+src="([^"]+)"/is', $target, $imgs);
            if (empty($imgs[1])) {
                return null;
            }

            $pages = [];
            foreach ($imgs[1] as $idx => $directUrl) {
                if (str_contains($directUrl, 'logo') || str_contains($directUrl, 'iklan')) continue;
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

