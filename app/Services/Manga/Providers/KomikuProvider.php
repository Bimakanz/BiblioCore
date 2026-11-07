<?php

namespace App\Services\Manga\Providers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class KomikuProvider
{
    protected string $baseUrl = 'https://komiku.org';
    protected array $dnsResolves = [
        'komiku.org:443:104.21.40.71',
        'komiku.org:443:172.67.180.174',
        'img.komiku.org:443:104.21.40.71',
        'img.komiku.org:443:172.67.180.174',
    ];

    /**
     * Request helper with DoH DNS bypass.
     */
    protected function request(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($ch, CURLOPT_RESOLVE, $this->dnsResolves);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_REFERER, 'https://komiku.org/');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $httpCode >= 400) {
            return null;
        }

        return $res;
    }

    /**
     * Generate possible slug candidates from manga title.
     */
    protected function getSlugCandidates(string $title, array $altTitles = []): array
    {
        $candidates = [];
        $clean = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s-]/', '', $title)));
        $candidates[] = preg_replace('/\s+/', '-', $clean);

        // Also add romanized or short versions
        foreach ($altTitles as $alt) {
            $cleanAlt = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s-]/', '', $alt)));
            if (!empty($cleanAlt)) {
                $candidates[] = preg_replace('/\s+/', '-', $cleanAlt);
            }
        }

        // Specific common aliases
        if (str_contains($clean, 'komi')) {
            $candidates[] = 'komi-san-wa-komyushou-desu';
            $candidates[] = 'komi-cant-communicate';
        }
        if (str_contains($clean, 'frieren')) {
            $candidates[] = 'sousou-no-frieren';
        }
        if (str_contains($clean, 'solo leveling') || str_contains($clean, 'honjaman')) {
            $candidates[] = 'solo-leveling';
        }
        if (str_contains($clean, 'slime') || str_contains($clean, 'tensei shitara')) {
            $candidates[] = 'tensei-shitara-slime-datta-ken';
        }
        if (str_contains($clean, 'kage no jitsuryokusha') || str_contains($clean, 'eminence in shadow')) {
            $candidates[] = 'kage-no-jitsuryokusha-ni-naritakute';
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Fetch Indonesian chapters from Komiku.
     */
    public function getChapters(string $title, array $altTitles = []): array
    {
        $cacheKey = 'komiku_ch_' . md5($title);

        return Cache::remember($cacheKey, 3600, function () use ($title, $altTitles) {
            $slugs = $this->getSlugCandidates($title, $altTitles);
            $html = null;
            $matchedSlug = null;

            foreach ($slugs as $slug) {
                $url = "{$this->baseUrl}/manga/{$slug}/";
                $res = $this->request($url);
                if ($res && str_contains($res, 'id="Daftar_Chapter"')) {
                    $html = $res;
                    $matchedSlug = $slug;
                    break;
                }
            }

            if (!$html) {
                return [];
            }

            // Extract chapters from table
            preg_match_all('/<td class="judulseries"[^>]*>.*?<a href="([^"]+)"[^>]*>.*?<b>Chapter\s*([0-9\.]+)<\/b>(?:.*?<span class="judulchapter">([^<]*)<\/span>)?.*?<\/td>(?:.*?<td class="tanggalseries">([^<]*)<\/td>)?/is', $html, $matches, PREG_SET_ORDER);

            $chapters = [];
            foreach ($matches as $m) {
                $path = $m[1];
                $chapNum = (string)$m[2];
                $chapTitle = trim($m[3] ?? '');
                $date = trim($m[4] ?? '');

                // Ensure unique ID with prefix kmk_
                $slugPath = trim(str_replace(['/', '.html'], '', $path));
                $chapters[] = [
                    'id' => 'kmk_' . $slugPath,
                    'chapter' => $chapNum,
                    'title' => $chapTitle,
                    'language' => 'id',
                    'group' => 'Komiku Indo',
                    'provider' => 'Komiku',
                    'publishAt' => $date ?: 'Komiku Archive',
                    'externalUrl' => null,
                    'readPath' => $path,
                ];
            }

            return $chapters;
        });
    }

    /**
     * Fetch image pages for a Komiku chapter.
     */
    public function getChapterPages(string $chapterSlug): ?array
    {
        $cacheKey = 'komiku_pages_' . md5($chapterSlug);

        return Cache::remember($cacheKey, 7200, function () use ($chapterSlug) {
            $slug = str_replace('kmk_', '', $chapterSlug);
            $url = "{$this->baseUrl}/{$slug}/";

            $html = $this->request($url);
            if (!$html) {
                return null;
            }

            // Extract images matching img.komiku.org
            preg_match_all('/https:\/\/img\.komiku\.org\/[^"\'>\s]+/i', $html, $matches);
            $rawImgs = array_values(array_filter(array_unique($matches[0]), function ($u) {
                return !str_contains($u, 'promosi') && !str_contains($u, 'logo') && !str_contains($u, 'iklan') && !str_contains($u, 'ico');
            }));

            if (empty($rawImgs)) {
                return null;
            }

            $pages = [];
            foreach ($rawImgs as $idx => $directUrl) {
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
                'chapterId' => 'kmk_' . $slug,
                'pages' => $pages,
            ];
        });
    }
}

