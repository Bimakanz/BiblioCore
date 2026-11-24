<?php

namespace App\Services\Anime\Concerns;

/**
 * Title matching shared by the HTML-scraped anime sources.
 *
 * Catalogue sites never use the same title as AniList: one ships the romaji
 * name, another the English one, a third appends "Season 2". Matching every
 * candidate title against every search hit (instead of only the query that
 * produced the hit) is what keeps a lookup from silently failing.
 */
trait MatchesTitles
{
    /**
     * Titles worth trying, best first.
     *
     * @param  array<string, mixed>  $anime
     * @return array<int, string>
     */
    protected function titleCandidates(array $anime, int $limit = 4): array
    {
        return array_slice($this->allTitles($anime), 0, $limit);
    }

    /**
     * Every distinct title a catalogue row carries.
     *
     * @param  array<string, mixed>  $anime
     * @return array<int, string>
     */
    protected function allTitles(array $anime): array
    {
        $titles = [(string) ($anime['title'] ?? '')];

        foreach (['titles', 'altTitles', 'synonyms', 'englishTitle'] as $field) {
            $value = $anime[$field] ?? null;
            if (is_string($value)) {
                $titles[] = $value;

                continue;
            }
            foreach ((is_array($value) ? $value : []) as $title) {
                $titles[] = (string) $title;
            }
        }

        $out = [];
        foreach ($titles as $title) {
            $title = trim($title);
            if ($title !== '' && ! in_array($title, $out, true)) {
                $out[] = $title;
            }
        }

        return $out;
    }

    protected function normaliseTitle(string $title): string
    {
        $title = strtolower(trim($title));
        // "2nd Season" and "Season 2" must land on the same tokens.
        $title = (string) preg_replace('/\b(\d+)(?:st|nd|rd|th)\b/', '$1', $title);
        $title = (string) preg_replace('/[^a-z0-9]+/', ' ', $title);

        return trim((string) preg_replace('/\s+/', ' ', $title));
    }

    /**
     * Similarity in the 0..1 range. Exact and containment matches score
     * highest, then token overlap, then character-level similarity.
     */
    protected function titleScore(string $a, string $b): float
    {
        $na = $this->normaliseTitle($a);
        $nb = $this->normaliseTitle($b);
        if ($na === '' || $nb === '') {
            return 0.0;
        }
        if ($na === $nb) {
            return 1.0;
        }

        $tokensA = $this->tokens($na);
        $tokensB = $this->tokens($nb);
        $jaccard = 0.0;
        if ($tokensA && $tokensB) {
            $intersection = count(array_intersect($tokensA, $tokensB));
            $union = count(array_unique(array_merge($tokensA, $tokensB)));
            $jaccard = $union > 0 ? $intersection / $union : 0.0;
        }

        similar_text($na, $nb, $percent);
        $ratio = $percent / 100;

        // "Frieren" inside "Frieren Beyond Journeys End Season 2" is a real
        // match, but containment alone must stay below an exact token match.
        $containment = (str_contains($na, $nb) || str_contains($nb, $na)) ? 0.75 : 0.0;

        return max($ratio, $jaccard * 0.95, $containment);
    }

    /**
     * @return array<int, string>
     */
    protected function tokens(string $normalised): array
    {
        $stop = ['the', 'a', 'an', 'of', 'no', 'to', 'in', 'on', 'and', 'wa', 'ga', 'o'];
        $words = array_values(array_filter(explode(' ', $normalised), function ($word) use ($stop) {
            return $word !== '' && ! in_array($word, $stop, true);
        }));

        return array_values(array_unique($words));
    }

    /**
     * The token that identifies a title best: the last meaningful word
     * ("Sousou no Frieren" -> "frieren"), falling back to the longest one.
     * Catalogue search engines rank by relevance, so a first hit sharing this
     * token is the same show even when the full titles look nothing alike.
     */
    protected function anchorToken(string $title): string
    {
        $tokens = $this->tokens($this->normaliseTitle($title));
        if (empty($tokens)) {
            return '';
        }

        $anchor = '';
        foreach ($tokens as $token) {
            if (strlen($token) >= 4) {
                $anchor = $token;
            }
        }

        if ($anchor !== '') {
            return $anchor;
        }

        usort($tokens, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $tokens[0];
    }
}
