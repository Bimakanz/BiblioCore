<?php

namespace Tests\Feature;

use App\Services\Anime\AnimeExtraSources;
use App\Services\Anime\AnimeHeavenSource;
use App\Services\Anime\AnimeStreamProvider;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AnimeHeavenSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Provider with the network layer replaced by canned pages. AnimeHeaven
     * is fetched through curl (no JSON API), so Http::fake() cannot see it.
     *
     * @param  array<string, string>  $fixtures  url fragment => body
     */
    protected function provider(array $fixtures): AnimeHeavenSource
    {
        return new class($fixtures) extends AnimeHeavenSource
        {
            /** @param array<string, string> $fixtures */
            public function __construct(private array $fixtures)
            {
                parent::__construct();
            }

            protected function fetch(string $url, array $headers = [], int $timeout = 12): ?string
            {
                foreach ($this->fixtures as $needle => $body) {
                    if (str_contains($url, $needle)) {
                        return $body;
                    }
                }

                return null;
            }
        };
    }

    protected function searchPage(): string
    {
        return <<<'HTML'
<div class='boldtext'><div class='linetitle c'>frieren</div><div class='info3 bc1'>
<div class='similarimg'><div class='p1'><a href='anime.php?ak2gr'><img class='coverimg' src='image.php?z4mfi' alt='Frieren: Beyond Journey&#039;s End' loading='lazy'></a><div class='similarname c'><a href='anime.php?ak2gr' class='c'>Frieren: Beyond Journey&#039;s End</a></div></div></div>
<div class='similarimg'><div class='p1'><a href='anime.php?hgj3i'><img class='coverimg' src='image.php?bb1' alt='Frieren: Beyond Journey&#039;s End Season 2' loading='lazy'></a></div></div>
</div></div>
HTML;
    }

    protected function animePage(): string
    {
        return <<<'HTML'
<div class='boldtext'> <div class='linetitle2 c2'>
<a class='c' id ="47edc338ae59c34cc3ea427c7f6871df" onclick='gatea( "47edc338ae59c34cc3ea427c7f6871df")' onmouseover='gateh( "47edc338ae59c34cc3ea427c7f6871df")' href= 'gate.php'><div class='trackep0 watch bc2'><div class='trackep watchb bc'><div class='watch1 bc c'>Episode</div><div  class= ' watch2 bc '  >28</div><div class='watch1 bc c'>311 d ago</div></div></div></a>
<a class='c' id ="0437d20b317e2834760f61c83f8c973a" onclick='gatea( "0437d20b317e2834760f61c83f8c973a")' onmouseover='gateh( "0437d20b317e2834760f61c83f8c973a")' href= 'gate.php'><div class='trackep0 watch bc2'><div class='trackep watchb bc'><div class='watch1 bc c'>Episode</div><div  class= ' watch2 bc '  >27</div><div class='watch1 bc c'>318 d ago</div></div></div></a>
HTML;
    }

    protected function gatePage(): string
    {
        return <<<'HTML'
<!-- <source src='https://ax.animeheaven.me/video.mp4?2c523c8cd9cc&eed805' type='video/mp4'> -->
<source src='https://ci.animeheaven.me/video.mp4?47edc338ae59c34cc3ea427c7f6871df&303a3fbb' type='video/mp4' onerror="xhr()">
<source src='https://ct.animeheaven.me/video.mp4?47edc338ae59c34cc3ea427c7f6871df&303a3fbb&error' type='video/mp4' onerror="xhr()">
HTML;
    }

    public function test_it_maps_a_title_to_episode_ids(): void
    {
        $provider = $this->provider([
            'search.php' => $this->searchPage(),
            'anime.php?ak2gr' => $this->animePage(),
            'gate.php' => $this->gatePage(),
        ]);

        $episodes = $provider->episodesForAniList(154587, [
            'title' => 'Frieren: Beyond Journey\'s End',
            'altTitles' => ['Sousou no Frieren'],
        ]);

        $this->assertCount(2, $episodes);
        $this->assertSame('ah|ak2gr|27', $episodes[0]['id']);
        $this->assertSame('ah|ak2gr|28', $episodes[1]['id']);
        $this->assertSame(27, $episodes[0]['number']);
    }

    public function test_it_prefers_the_english_title_match(): void
    {
        $provider = $this->provider([
            'search.php' => $this->searchPage(),
            'anime.php?ak2gr' => $this->animePage(),
        ]);

        $episodes = $provider->episodesForAniList(154587, ['title' => 'Sousou no Frieren']);

        // The romaji query is what the site understands; scoring against the
        // English hit still resolves the same show.
        $this->assertSame('ah|ak2gr|27', $episodes[0]['id']);
    }

    public function test_it_picks_the_season_row_not_the_first_season(): void
    {
        $provider = $this->provider([
            'search.php' => $this->searchPage(),
            'anime.php?hgj3i' => $this->animePage(),
        ]);

        $episodes = $provider->episodesForAniList(182255, [
            'title' => 'Frieren: Beyond Journey\'s End Season 2',
            'titles' => ['romaji' => 'Sousou no Frieren 2nd Season'],
        ]);

        $this->assertNotEmpty($episodes);
        $this->assertSame('ah|hgj3i|27', $episodes[0]['id']);
    }

    public function test_a_forced_source_only_accepts_a_burned_in_file(): void
    {
        Cache::put('anime_titles_5', ['Some Show'], 3600);

        $extra = new class extends AnimeExtraSources
        {
            public function animeHeavenStreamFor(array $anime, int $number): ?array
            {
                // Samehadaku/AnimeHeaven may answer with a third-party iframe;
                // the picker must not label that as burned-in playback.
                return [
                    'sources' => [['url' => 'https://www.blogger.com/video.g?token=x']],
                    'subtitles' => [],
                    'kind' => 'embed',
                    'embed' => 'https://www.blogger.com/video.g?token=x',
                    'provider' => 'AnimeHeaven',
                ];
            }
        };

        $provider = new class($extra) extends AnimeStreamProvider
        {
            public function forced(int $id, int $number, string $source): ?array
            {
                return $this->hardsubFor($id, $number, $source);
            }
        };

        $this->assertNull($provider->forced(5, 1, 'animeheaven'));
    }

    public function test_it_returns_a_direct_mp4_stream(): void
    {
        $provider = $this->provider([
            'anime.php?ak2gr' => $this->animePage(),
            'gate.php' => $this->gatePage(),
        ]);

        $stream = $provider->streamFor('ah|ak2gr|28');

        $this->assertNotNull($stream);
        $this->assertSame('mp4', $stream['kind']);
        $this->assertSame('AnimeHeaven', $stream['provider']);
        $this->assertTrue($stream['hard_sub']);
        $this->assertStringStartsWith('https://ci.animeheaven.me/video.mp4', $stream['sources'][0]['url']);
    }

    public function test_it_fails_soft_for_an_unknown_title(): void
    {
        $provider = $this->provider([
            'search.php' => '<html><body>No results</body></html>',
        ]);

        $this->assertSame([], $provider->episodesForAniList(1, ['title' => 'Nothing Here']));
        $this->assertNull($provider->streamForEpisode(['title' => 'Nothing Here'], 1));
        $this->assertNull($provider->streamFor('ah|missing|1'));
    }

    public function test_stream_provider_routes_the_ah_prefix_back_to_the_source(): void
    {
        $extra = new class extends AnimeExtraSources
        {
            public function animeHeavenStream(string $reference): ?array
            {
                return [
                    'sources' => [['url' => 'https://ci.animeheaven.me/video.mp4?x', 'quality' => 'auto']],
                    'subtitles' => [],
                    'kind' => 'mp4',
                    'provider' => 'AnimeHeaven',
                ];
            }
        };

        $stream = (new AnimeStreamProvider($extra))->getStream('ah|ak2gr|1');

        $this->assertSame('mp4', $stream['kind']);
        $this->assertSame('AnimeHeaven', $stream['provider']);
    }

    public function test_it_offers_both_burned_in_libraries_as_player_modes(): void
    {
        $modes = (new AnimeStreamProvider())->modes();
        $byId = [];
        foreach ($modes as $mode) {
            $byId[$mode['id']] = $mode;
        }

        $this->assertArrayHasKey('', $byId);
        $this->assertSame('en', $byId['animeheaven']['lang']);
        $this->assertSame('id', $byId['samehadaku']['lang']);
        $this->assertSame('Burned-in · EN', $byId['animeheaven']['label']);
        $this->assertSame('Burned-in · ID', $byId['samehadaku']['label']);
        $this->assertTrue($byId['animeheaven']['hardSub']);
        $this->assertFalse($byId['']['hardSub']);
    }

    public function test_a_forced_burned_in_mode_returns_the_matching_file(): void
    {
        Cache::put('anime_titles_7', ['Some Show'], 3600);

        $extra = new class extends AnimeExtraSources
        {
            public function animeHeavenStreamFor(array $anime, int $number): ?array
            {
                return [
                    'sources' => [['url' => 'https://ci.animeheaven.me/video.mp4?x', 'quality' => 'auto']],
                    'subtitles' => [],
                    'kind' => 'mp4',
                    'provider' => 'AnimeHeaven',
                    'hard_sub' => true,
                    'hard_sub_lang' => 'en',
                ];
            }
        };

        $stream = (new AnimeStreamProvider($extra))->getStream('watch/kaa/7/sub/kaa-1', 'animeheaven');

        $this->assertNotNull($stream);
        $this->assertSame('mp4', $stream['kind']);
        $this->assertSame('en', $stream['hard_sub_lang']);
        $this->assertArrayNotHasKey('hardsub_fallback', $stream);
    }

    public function test_a_missing_burned_in_copy_falls_back_to_the_standard_stream(): void
    {
        Cache::put('anime_titles_9', ['Some Show'], 3600);

        $extra = new class extends AnimeExtraSources
        {
            public function animeHeavenStreamFor(array $anime, int $number): ?array
            {
                return null;
            }
        };

        $provider = new class($extra) extends AnimeStreamProvider
        {
            protected function fetchStream(string $watchPath): ?array
            {
                return [
                    'sources' => [['url' => 'https://example.com/master.m3u8', 'quality' => 'auto']],
                    'subtitles' => [],
                    'kind' => 'hls',
                    'provider' => 'anikoto',
                ];
            }
        };

        $stream = $provider->getStream('watch/anikoto/9/sub/anikoto-1', 'animeheaven');

        $this->assertNotNull($stream);
        $this->assertSame('hls', $stream['kind']);
        $this->assertSame('en', $stream['hardsub_fallback']);
    }
}
