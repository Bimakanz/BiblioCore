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
        if (count($tokensA) >= 3 && count($tokensB) >= 3 && count(array_intersect($tokensA, $tokensB)) <= 1) {
            $ratio = min($ratio, 0.55);
        }

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
     * Words that describe the release, not the show. Matching on them finds
     * random sequels ("season 2" -> Black Clover Season 2), so they are
     * dropped from anchors and search queries.
     *
     * @return array<int, string>
     */
    protected function genericTokens(): array
    {
        return [
            'season', 'seasons', 'part', 'parts', 'cour', 'movie', 'special',
            'specials', 'ova', 'ona', 'final', 'finale', 'end', 'arc', 'chapter',
            's', 'ii', 'iii', 'iv',
        ];
    }

    /**
     * Words common enough across unrelated anime titles that sharing just one
     * of them is not proof of a match ("High School DxD" vs "Daily Lives of
     * High School Boys").
     *
     * @return array<int, string>
     */
    protected function weakAnchorTokens(): array
    {
        return [
            'school', 'high', 'daily', 'lives', 'life', 'days', 'boys', 'girls',
            'world', 'love', 'story', 'hero', 'magic', 'black', 'white', 'blue',
            'zero', 'time', 'house', 'club', 'king', 'queen', 'angel', 'death',
            'blood', 'sword', 'night', 'summer', 'winter', 'spring', 'another',
            'nichijou', 'monogatari', 'koukousei', 'gakuen', 'seishun', 'sensei',
            'isekai', 'tensei', 'mahou', 'yuusha', 'kawaii', 'kanojo', 'kareshi',
        ];
    }

    /**
     * @param  array<int, string>  $hitTokens
     */
    protected function sharesStrongAnchor(string $anchor, array $hitTokens): bool
    {
        return $anchor !== ''
            && strlen($anchor) >= 7
            && ! in_array($anchor, $this->weakAnchorTokens(), true)
            && in_array($anchor, $hitTokens, true);
    }

    /**
     * Non-generic, non-numeric tokens in the order they appear.
     *
     * @return array<int, string>
     */
    protected function distinctiveTokens(string $title): array
    {
        $generic = $this->genericTokens();
        $out = [];
        foreach ($this->tokens($this->normaliseTitle($title)) as $token) {
            if ($token === '' || ctype_digit($token) || in_array($token, $generic, true)) {
                continue;
            }
            $out[$token] = true;
        }

        return array_keys($out);
    }

    /**
     * The token that identifies a title best: the longest distinctive word
     * ("Frieren: Beyond Journey's End Season 2" -> "frieren"). Catalogue
     * search engines rank by relevance, so a first hit sharing this token is
     * the same show even when the full titles look nothing alike.
     */
    protected function anchorToken(string $title): string
    {
        $tokens = $this->distinctiveTokens($title);
        if (empty($tokens)) {
            return '';
        }

        $order = array_flip($tokens);
        usort($tokens, function ($a, $b) use ($order) {
            $byLength = strlen($b) <=> strlen($a);
            if ($byLength !== 0) {
                return $byLength;
            }

            return $order[$a] <=> $order[$b];
        });

        return $tokens[0];
    }

    /**
     * Queries worth spending a request on, best first. The full title comes
     * first because some catalogues index it verbatim; when it returns
     * nothing, the distinctive word(s) still resolve the show.
     *
     * @return array<int, string>
     */
    protected function searchQueries(string $title): array
    {
        $queries = [trim($title)];

        $clean = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $title);
        $clean = trim((string) preg_replace('/\s+/', ' ', $clean));
        if ($clean !== '') {
            $queries[] = $clean;
        }

        $tokens = $this->distinctiveTokens($title);
        $order = array_flip($tokens);
        usort($tokens, function ($a, $b) use ($order) {
            $byLength = strlen($b) <=> strlen($a);
            if ($byLength !== 0) {
                return $byLength;
            }

            return $order[$a] <=> $order[$b];
        });
        foreach (array_slice($tokens, 0, 2) as $token) {
            $queries[] = $token;
        }

        $out = [];
        foreach ($queries as $query) {
            $query = trim($query);
            if ($query !== '' && ! in_array($query, $out, true)) {
                $out[] = $query;
            }
        }

        return $out;
    }

    /**
     * Season/part number a title declares, or null when it declares none.
     * "2nd Season", "Season 2", "Part 2" and "Cour 2" all answer 2.
     */
    protected function seasonHint(string $title): ?int
    {
        $normalised = $this->normaliseTitle($title);
        foreach ([
            '/\bseason\s*(\d{1,2})\b/',
            '/\b(\d{1,2})\s*season\b/',
            '/\bpart\s*(\d{1,2})\b/',
            '/\b(\d{1,2})\s*part\b/',
            '/\bcour\s*(\d{1,2})\b/',
        ] as $pattern) {
            if (preg_match($pattern, $normalised, $m)) {
                return (int) $m[1];
            }
        }

        $roman = ['ii' => 2, 'iii' => 3, 'iv' => 4];
        if (preg_match('/\b(ii|iii|iv)\b/', $normalised, $m)) {
            return $roman[$m[1]];
        }

        return null;
    }

    /**
     * Push a candidate towards the requested season and away from its
     * neighbours: the same show's first and second season share almost every
     * token, so plain similarity alone serves the wrong cour.
     */
    protected function adjustForSeason(string $title, string $candidate, float $score): float
    {
        $wanted = $this->seasonHint($title);
        $found = $this->seasonHint($candidate);

        if ($wanted !== null && $found !== null) {
            return $wanted === $found ? min(1.0, $score + 0.3) : $score * 0.35;
        }

        // The title names no season, so a "season 2" hit is a different cour.
        if ($found !== null && $found >= 2 && $wanted === null) {
            return $score * 0.5;
        }

        // Do not let "Specials", "OVA", or "Live Action" outrank the TV series
        // unless the requested title explicitly asks for that release kind.
        $normTitle = $this->normaliseTitle($title);
        $normCandidate = $this->normaliseTitle($candidate);
        foreach (['specials', 'special', 'ova', 'ona', 'movie', 'live action'] as $extra) {
            $wantedExtra = preg_match('/\b'.preg_quote($extra, '/').'\b/', $normTitle) === 1;
            $foundExtra = preg_match('/\b'.preg_quote($extra, '/').'\b/', $normCandidate) === 1;
            if ($foundExtra && ! $wantedExtra) {
                return $score * 0.55;
            }
        }

        return $score;
    }
}
