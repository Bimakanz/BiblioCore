<?php

namespace App\Services\Anime;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort English -> Indonesian subtitle translation.
 *
 * Takes an English WebVTT track, translates only the cue TEXT (timings are
 * copied verbatim, so sync is preserved) and caches the result for 30 days:
 * an episode is translated once, every later viewer gets it instantly.
 *
 * Safety properties:
 * - Translation runs in chunks; if a chunk comes back with a different cue
 *   count than it was sent with, that chunk silently keeps the English text
 *   instead of corrupting the timeline.
 * - Any failure returns null and the player simply keeps the English track.
 */
class SubtitleTranslator
{
    protected function fetchText(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }
        // SSRF guard, same approach as MangaController::proxyImage.
        $ip = gethostbyname($host);
        $ipToCheck = filter_var($host, FILTER_VALIDATE_IP) ? $host : $ip;
        if (!filter_var($ipToCheck, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Single retry for cold/flaky CDNs. Never retried on 4xx-5xx: a
        // missing track stays missing and must not cost another 12s.
        if ($res === false) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            $res = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        return ($res === false || $code >= 400) ? null : $res;
    }

    protected function translateChunk(array $lines): array
    {
        $text = implode("\n", $lines);
        $url = 'https://translate.googleapis.com/translate_a/single?client=gtx&sl=en&tl=id&dt=t&q=' . urlencode($text);
        $json = $this->fetchText($url);
        if ($json === null) {
            return $lines;
        }
        $data = json_decode($json, true);
        if (!isset($data[0]) || !is_array($data[0])) {
            return $lines;
        }
        $translated = '';
        foreach ($data[0] as $segment) {
            $translated .= $segment[0] ?? '';
        }
        $out = explode("\n", trim($translated));
        // Alignment check: never risk a shifted timeline.
        if (count($out) !== count($lines)) {
            return $lines;
        }
        return array_map(fn ($l) => trim($l) === '' ? $l : trim($l), $out);
    }

    /**
     * Return Indonesian VTT content for an English VTT URL, or null.
     */
    public function indonesianVtt(string $englishVttUrl): ?string
    {
        $cacheKey = 'anime_sub_id_' . md5($englishVttUrl);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $vtt = Cache::remember('anime_sub_raw_' . md5($englishVttUrl), 21600, function () use ($englishVttUrl) {
            return $this->fetchText($englishVttUrl);
        });
        if (!is_string($vtt) || $vtt === '' || !str_contains($vtt, '-->')) {
            return null;
        }

        $blocks = preg_split('/\r?\n\r?\n/', trim($vtt));
        $header = [];
        $cues = []; // each: ['timing' => string, 'text' => string]

        foreach ($blocks as $block) {
            $lines = explode("\n", str_replace("\r\n", "\n", $block));
            $timingIdx = null;
            foreach ($lines as $i => $line) {
                if (str_contains($line, '-->')) {
                    $timingIdx = $i;
                    break;
                }
            }
            if ($timingIdx === null) {
                $header[] = $block;
                continue;
            }
            $textLines = array_slice($lines, $timingIdx + 1);
            $plain = trim(strip_tags(implode(' ', $textLines)));
            $cues[] = [
                'timing' => $lines[$timingIdx],
                'text' => $plain,
            ];
        }

        if (empty($cues) || count($cues) > 1500) {
            return null;
        }

        // Translate in ~3200-char chunks of flattened cue texts.
        $texts = array_map(fn ($c) => $c['text'] === '' ? '...' : $c['text'], $cues);
        $translated = [];
        $chunk = [];
        $chunkLen = 0;
        $flush = function () use (&$chunk, &$translated, &$chunkLen) {
            if (!empty($chunk)) {
                $translated = array_merge($translated, $this->translateChunk($chunk));
                $chunk = [];
                $chunkLen = 0;
            }
        };
        foreach ($texts as $t) {
            if ($chunkLen + strlen($t) > 3200 && !empty($chunk)) {
                $flush();
            }
            $chunk[] = $t;
            $chunkLen += strlen($t) + 1;
        }
        $flush();

        if (count($translated) !== count($cues)) {
            Log::warning('Subtitle translation alignment failed, serving none');
            return null;
        }

        $out = "WEBVTT - Indonesian (Auto)\n\n";
        foreach ($cues as $i => $cue) {
            $out .= $cue['timing'] . "\n" . $translated[$i] . "\n\n";
        }

        Cache::put($cacheKey, $out, 2592000);
        return $out;
    }
}
