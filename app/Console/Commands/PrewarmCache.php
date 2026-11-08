<?php

namespace App\Console\Commands;

use App\Http\Controllers\AnimeController;
use App\Services\Anime\AnimeService;
use App\Services\Anime\AnimeStreamProvider;
use App\Services\Manga\Providers\AniListProvider;
use Illuminate\Console\Command;

/**
 * Warms the expensive cold paths ahead of real users: anime home sections,
 * the top titles' detail/episodes/first stream/recommendations, and the
 * manga catalog. Cold scrapes measured 2.6-6s; warmed hits answer in
 * ~0.3s. Safe to run repeatedly; every step is isolated so one failure
 * never aborts the rest.
 */
class PrewarmCache extends Command
{
    protected $signature = 'cache:prewarm {--titles=10 : How many top anime titles to warm fully}';

    protected $description = 'Prewarm home/catalog/detail/stream caches for popular titles';

    public function handle(AnimeService $animeService, AnimeStreamProvider $streamProvider): int
    {
        $limit = max(1, (int) $this->option('titles'));

        // 1) Anime home sections (single aliased AniList query, cached).
        $sections = [];
        try {
            $sections = $animeService->homeSections();
            // Hero stills (banner/YouTube checks) are cached separately by
            // enrichHeroImages; warm them too or the first home hit still
            // pays ~1s for the checks.
            if (!empty($sections['trending'])) {
                $animeService->enrichHeroImages($sections['trending']);
            }
            $this->info('Anime home sections + hero images warmed.');
        } catch (\Throwable $e) {
            $this->warn('Home sections failed: ' . $e->getMessage());
        }

        // 2) Top titles: detail + episodes + first stream + recommendations.
        $cards = array_merge($sections['trending'] ?? [], $sections['top'] ?? [], $sections['season'] ?? []);
        $seen = [];
        $warmed = 0;
        foreach ($cards as $card) {
            if ($warmed >= $limit) {
                break;
            }
            $id = $card['id'] ?? null;
            if (!$id || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            try {
                $detail = $animeService->getDetail($id);
                if (!$detail) {
                    continue;
                }
                $anilistId = (int) ($detail['anilistId'] ?? 0);
                if ($anilistId > 0) {
                    $eps = $streamProvider->getEpisodes($anilistId);
                    $first = $eps['episodes'][0]['id'] ?? null;
                    if ($first) {
                        // Go through streamJson itself so the verified-pair
                        // cache and fastest-provider choice are also warm;
                        // warming only the raw stream data left ~2s of
                        // verification work for the first real viewer.
                        $req = \Illuminate\Http\Request::create('/anime/' . $id . '/stream.json', 'GET', ['ep' => $first]);
                        app(AnimeController::class)->streamJson($id, $req);
                    }
                }
                app(AnimeController::class)->recommendationsJson($id);
                $warmed++;
                $this->info("Warmed {$id} ({$detail['title']})");
            } catch (\Throwable $e) {
                $this->warn("Title {$id} failed: " . $e->getMessage());
            }
        }

        // 3) Manga catalog page 1 (AniList) so the manga home opens warm.
        try {
            app(AniListProvider::class)->getPopular(1, 24, 'popular');
            $this->info('Manga catalog warmed.');
        } catch (\Throwable $e) {
            $this->warn('Manga catalog failed: ' . $e->getMessage());
        }

        $this->info("Prewarm complete: {$warmed} titles.");

        return self::SUCCESS;
    }
}
