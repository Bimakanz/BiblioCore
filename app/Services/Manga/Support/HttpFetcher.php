<?php

namespace App\Services\Manga\Support;

/**
 * Small curl_multi helper used to fetch several pages for a provider in one
 * concurrent wave. Providers normally discover a manga through a search page
 * before fetching the detail page; running those candidates concurrently cuts
 * a lot of dead waiting time on cold requests.
 */
class HttpFetcher
{
    /**
     * Fetch many URLs concurrently.
     *
     * @param  array<int, string>  $urls
     * @param  array{resolve?: array<int, string>, referer?: string, headers?: array<int, string>, timeout?: float, connect_timeout?: float}  $options
     * @return array<string, string|null> Map of url => body (null on failure)
     */
    public static function getMany(array $urls, array $options = []): array
    {
        $urls = array_values(array_unique(array_filter($urls, fn ($url) => is_string($url) && $url !== '')));
        if (empty($urls)) {
            return [];
        }

        $isVercel = isset($_ENV['VERCEL']) || isset($_SERVER['VERCEL']) || env('VERCEL');

        // Mirror the existing provider behaviour: try standard DNS first on
        // Vercel, and a pinned DoH/IP map first during local development.
        $primaryResolve = ! $isVercel;

        [$results, $retryable] = self::execute($urls, $primaryResolve, $options);

        // Only retry transport-level failures (no response, timeout, 5xx,
        // 429) through the alternate DNS strategy. A definitive 4xx answer
        // (e.g. 404) will not change on retry and previously wasted a full
        // second wave (~4s) on every cold request that hit one.
        if (! empty($retryable)) {
            [$retry] = self::execute($retryable, ! $primaryResolve, $options);
            foreach ($retry as $url => $body) {
                if ($body !== null) {
                    $results[$url] = $body;
                }
            }
        }

        return $results;
    }

    /**
     * @param  array<int, string>  $urls
     * @return array{0: array<string, string|null>, 1: array<int, string>} Results map plus the URLs worth retrying (transport failures only)
     */
    protected static function execute(array $urls, bool $useResolve, array $options): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $results = [];

        $timeout = (float) ($options['timeout'] ?? 4);
        $connectTimeout = (float) ($options['connect_timeout'] ?? 2);
        $resolve = $useResolve ? ($options['resolve'] ?? []) : [];

        foreach ($urls as $url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, (int) ($connectTimeout * 1000));
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, (int) ($timeout * 1000));
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');

            if (! empty($resolve)) {
                curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
            }
            if (! empty($options['referer'])) {
                curl_setopt($ch, CURLOPT_REFERER, $options['referer']);
            }
            if (! empty($options['headers'])) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
            }

            curl_multi_add_handle($multi, $ch);
            $handles[$url] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                $selected = curl_multi_select($multi, 1.0);
                if ($selected === -1) {
                    usleep(10000);
                }
            }
        } while ($running && $status === CURLM_OK);

        $retryable = [];
        foreach ($handles as $url => $ch) {
            $body = curl_multi_getcontent($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $ok = $body !== false && $body !== '' && $httpCode >= 200 && $httpCode < 400;
            $results[$url] = $ok ? $body : null;

            if (! $ok) {
                // Retry only when no definitive answer came back: connection
                // failure/timeout (code 0), rate limit, or a server error.
                // Other 4xx responses are final.
                if ($httpCode === 0 || $httpCode === 429 || $httpCode >= 500) {
                    $retryable[] = $url;
                }
            }

            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }

        curl_multi_close($multi);

        return [$results, $retryable];
    }
}
