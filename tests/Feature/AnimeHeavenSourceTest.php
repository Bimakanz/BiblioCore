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
}
