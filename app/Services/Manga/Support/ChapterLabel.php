<?php

namespace App\Services\Manga\Support;

/**
 * Normalises the human labels used by chapter lists into a number.
 *
 * Providers are inconsistent: "Chapter 12", "Ch. 12.5", "Vol. 2 Ch. 7",
 * "Extra 36", "Side Story 3", some even append "Last Read <date>". A plain
 * `Chapter|Ch.` regex silently turns every unrecognised label into "0",
 * which then collapses an entire series into a single chapter after
 * deduplication. This helper reads the label instead of assuming a format.
 */
final class ChapterLabel
{
    public static function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = str_replace(["\xC2\xA0", "\u{00A0}"], ' ', $text);

        // WeebCentral appends a per-user "Last Read <ISO date>" marker inside
        // the anchor, which would otherwise contribute digits to the number.
        $marker = stripos($text, 'Last Read');
        if ($marker !== false) {
            $text = substr($text, 0, $marker);
        }
        $text = preg_replace('/\s+/u', ' ', (string) $text);

        return trim((string) $text);
    }

    /**
     * Extract the chapter number from a label, falling back to the item's
     * position in the list when the label has no number at all.
     */
    public static function number(string $text, int $fallback = 0): string
    {
        $label = self::clean($text);

        if (preg_match('/(?:chapter|ch\.?|episode|ep\.?)\s*#?\s*([0-9]+(?:\.[0-9]+)?)/i', $label, $match)) {
            return self::normalise($match[1]);
        }

        // "Extra 36", "Side Story 3", "Season 2 - 15": use the last numeric
        // token. Dates have already been stripped by clean().
        if (preg_match_all('/(?<![0-9])([0-9]+(?:\.[0-9]+)?)(?![0-9])/', $label, $matches) && ! empty($matches[1])) {
            return self::normalise((string) end($matches[1]));
        }

        return $fallback > 0 ? (string) $fallback : '0';
    }

    protected static function normalise(string $number): string
    {
        if (! str_contains($number, '.')) {
            return ltrim($number, '0') === '' ? '0' : ltrim($number, '0');
        }

        $number = rtrim(rtrim($number, '0'), '.');

        return $number === '' ? '0' : $number;
    }
}
