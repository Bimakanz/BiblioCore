<?php

namespace App\Services\Anime\Concerns;

/**
 * Shared direct-MP4 extractors for Indonesian anime mirrors that wrap
 * direct video files inside Blogger or YourUpload player pages.
 */
trait ResolvesVideoEmbeds
{
    /**
     * Extract direct GoogleVideo MP4 sources from a Blogger video.g player URL.
     *
     * @param  callable(string, array<int, string>): ?string  $fetcher
     * @return array<int, array{url: string, quality: string, isM3U8: bool, referer?: ?string}>
     */
    protected function extractBloggerMp4s(string $bloggerUrl, callable $fetcher): array
    {
        $html = $fetcher($bloggerUrl, []);
        if ($html === null || $html === '') {
            return [];
        }

        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = str_replace(['\\u003d', '\\u0026', '\\/', '\\"'], ['=', '&', '/', '"'], $decoded);

        $formats = [];
        if (preg_match_all(
            '#\{"itag":\d+,"url":"(https://[^"]*googlevideo\.com/videoplayback\?[^"]+)"[^}]*"qualityLabel":"([^"]+)"#i',
            $decoded,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $row) {
                $url = trim((string) $row[1]);
                $quality = strtolower(trim((string) $row[2]));
                if ($url !== '') {
                    $formats[$url] = $quality !== '' ? $quality : 'auto';
                }
            }
        }

        if (empty($formats) && preg_match(
            '#https://[^"\'\\\\]+googlevideo\.com/videoplayback\?[^"\'\\\\]+#i',
            $decoded,
            $match
        )) {
            $formats[trim($match[0])] = '360p';
        }

        if (empty($formats)) {
            return [];
        }

        $out = [];
        foreach ($formats as $url => $quality) {
            $out[] = [
                'url' => $url,
                'quality' => $quality,
                'isM3U8' => false,
                'referer' => null,
            ];
        }

        usort($out, fn ($a, $b) => $this->embedQualityRank($b['quality']) <=> $this->embedQualityRank($a['quality']));

        return $out;
    }

    /**
     * Extract direct MP4 source from a YourUpload embed URL.
     *
     * @param  callable(string, array<int, string>): ?string  $fetcher
     * @return array{url: string, quality: string, isM3U8: bool, referer: string}|null
     */
    protected function extractYourUploadMp4(string $embedUrl, string $referer, callable $fetcher): ?array
    {
        $html = $fetcher($embedUrl, ['Referer: '.$referer]);
        if ($html === null || $html === '') {
            return null;
        }

        if (! preg_match('#\bfile\s*:\s*[\'"](https?://[^\'"]+\.mp4(?:\?[^\'"]*)?)[\'"]#i', $html, $m)) {
            return null;
        }

        $mp4 = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($mp4 === '') {
            return null;
        }

        return [
            'url' => $mp4,
            'quality' => '720p',
            'isM3U8' => false,
            'referer' => 'https://www.yourupload.com/',
        ];
    }

    protected function embedQualityRank(string $quality): int
    {
        $q = strtolower($quality);
        if (str_contains($q, '1080')) {
            return 4;
        }
        if (str_contains($q, '720')) {
            return 3;
        }
        if (str_contains($q, '480')) {
            return 2;
        }
        if (str_contains($q, '360')) {
            return 1;
        }

        return 0;
    }
}
