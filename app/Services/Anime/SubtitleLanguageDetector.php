<?php

namespace App\Services\Anime;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Detects the real language of a WebVTT track from its cue text.
 *
 * Providers routinely lie about `srclang`: anikoto, for example, tags every
 * one of its 33 tracks as "en" while the first one is Simplified Chinese.
 * Labels are usually honest, but even a label can be wrong, so before the
 * player trusts an "English" label we sample the actual cues.
 */
class SubtitleLanguageDetector
{
    /**
     * Detect the language of a remote VTT track. Returns a language code
     * ('en', 'id', 'zh', 'ja', 'ko', 'ar', ...), 'sprite' for thumbnail
     * tracks, or null when the track cannot be sampled/classified.
     */
    public function detect(string $url): ?string
    {
        $cacheKey = 'anime_sublang_' . md5($url);
        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            return $cached === 'unknown' ? null : $cached;
        }

        $sample = $this->fetchSample($url);
        if ($sample === null) {
            // Do not cache transient fetch failures: the next request may
            // reach the CDN fine.
            return null;
        }

        $lang = $this->classify($sample);
        Cache::put($cacheKey, $lang ?? 'unknown', 21600);

        return $lang;
    }

    /** Classify a VTT document (first cues only). */
    public function classify(string $vtt): ?string
    {
        $texts = [];
        $lines = preg_split("/\r\n|\n|\r/", $vtt);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'WEBVTT') || str_starts_with($line, 'NOTE')
                || str_contains($line, '-->') || preg_match('/^\d+$/', $line)) {
                continue;
            }
            $line = trim(strip_tags($line));
            if ($line !== '') {
                $texts[] = $line;
            }
            if (count($texts) >= 30) {
                break;
            }
        }

        $sample = implode(' ', $texts);
        if (trim($sample) === '') {
            return null;
        }
        if (str_contains($sample, 'thumbnails/sprite') || str_contains($sample, '#xywh=')) {
            return 'sprite';
        }

        $kana = preg_match_all('/[\x{3040}-\x{30FF}]/u', $sample);
        $han = preg_match_all('/[\x{4E00}-\x{9FFF}]/u', $sample);
        $hangul = preg_match_all('/[\x{AC00}-\x{D7AF}]/u', $sample);
        $arabic = preg_match_all('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}]/u', $sample);
        $thai = preg_match_all('/[\x{0E00}-\x{0E7F}]/u', $sample);
        $cyrillic = preg_match_all('/[\x{0400}-\x{04FF}]/u', $sample);
        $hebrew = preg_match_all('/[\x{0590}-\x{05FF}]/u', $sample);
        $latin = preg_match_all('/[A-Za-z]/', $sample);

        if ($hangul > 5 && $hangul >= $han) {
            return 'ko';
        }
        if ($kana > 5) {
            return 'ja';
        }
        if ($han > 5) {
            return 'zh';
        }
        if ($arabic > 5) {
            return 'ar';
        }
        if ($thai > 5) {
            return 'th';
        }
        if ($hebrew > 5) {
            return 'he';
        }
        if ($cyrillic > 10 && $cyrillic > $latin) {
            return 'ru';
        }
        if ($latin > 5) {
            return $this->classifyLatin($sample);
        }

        return null;
    }

    /** Latin script: distinguish English from Indonesian by stopwords. */
    protected function classifyLatin(string $sample): string
    {
        $lower = ' ' . mb_strtolower($sample, 'UTF-8') . ' ';
        $english = [' the ', ' and ', ' to ', ' of ', ' is ', ' you ', ' that ', ' it ', ' in ',
            ' this ', ' what ', ' for ', ' we ', ' not ', ' with ', ' on ', ' are ', ' be ', ' have '];
        $indonesian = [' yang ', ' dan ', ' di ', ' itu ', ' ini ', ' tidak ', ' aku ', ' kamu ',
            ' dengan ', ' untuk ', ' dari ', ' ke ', ' ada ', ' akan ', ' saya ', ' kita ',
            ' mereka ', ' sudah ', ' belum ', ' bisa ', ' adalah '];

        $enScore = 0;
        foreach ($english as $word) {
            $enScore += substr_count($lower, $word);
        }
        $idScore = 0;
        foreach ($indonesian as $word) {
            $idScore += substr_count($lower, $word);
        }

        if ($idScore >= 2 && $idScore > $enScore * 1.5) {
            return 'id';
        }

        return 'en';
    }

    /** Fetch only the head of a VTT file (enough for classification). */
    protected function fetchSample(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $ip = gethostbyname($host);
        $ipToCheck = filter_var($host, FILTER_VALIDATE_IP) ? $host : $ip;
        if (!filter_var($ipToCheck, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($res === false || $code >= 400 || !is_string($res) || $res === '') {
            Log::debug("Subtitle language sample failed ({$code}) for {$url}");
            return null;
        }

        return substr($res, 0, 20000);
    }
}
