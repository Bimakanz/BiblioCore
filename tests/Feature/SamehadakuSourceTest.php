<?php

namespace Tests\Feature;

use App\Services\Anime\SamehadakuSource;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SamehadakuSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * @param  array<string, string>  $fixtures  url fragment => body
     */
    protected function provider(array $fixtures): SamehadakuSource
    {
        return new class($fixtures) extends SamehadakuSource
        {
            /** @param array<string, string> $fixtures */
            public function __construct(private array $fixtures)
            {
                parent::__construct();
            }

            protected function fetch(string $url, array $headers = [], int $timeout = 15): ?string
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
<div class='postbody'>
<a href="https://v2.samehadaku.how/black-clover-season-2-episode-1/">1</a>
<a href="https://v2.samehadaku.how/sousou-no-frieren-season-2-episode-10-end/">10</a>
<a href="https://v2.samehadaku.how/sousou-no-frieren-episode-25/">25</a>
</div>
HTML;
    }

    protected function seriesPage(): string
    {
        return <<<'HTML'
<div class="flex items-center">
<a href="https://v2.samehadaku.how/sousou-no-frieren-episode-1/">1</a>
<a href="https://v2.samehadaku.how/sousou-no-frieren-episode-2/">2</a>
<a href="https://v2.samehadaku.how/sousou-no-frieren-episode-10-end/">10</a>
</div>
HTML;
    }

    protected function seasonTwoPage(): string
    {
        return <<<'HTML'
<div class="flex items-center">
<a href="https://v2.samehadaku.how/sousou-no-frieren-season-2-episode-1/">1</a>
<a href="https://v2.samehadaku.how/sousou-no-frieren-season-2-episode-10-end/">10</a>
</div>
HTML;
    }

    protected function episodePage(): string
    {
        return <<<'HTML'
<div class="player-area">
<div class="east_player_option" data-nume="3" data-embed="&lt;iframe src=&quot;https://s0.wibufile.com/video01/SnF-10-END-MP4HD-SAMEHADAKU.CARE.mp4&quot; class=&quot;w-full&quot;&gt;&lt;/iframe&gt;"></div>
<div class="east_player_option" data-nume="4" data-embed="&lt;iframe src=&quot;https://s0.wibufile.com/video01/SnF-10-END-FULLHD-SAMEHADAKU.CARE.mp4&quot; class=&quot;w-full&quot;&gt;&lt;/iframe&gt;"></div>
</div>
HTML;
    }

    public function test_it_resolves_episodes_for_a_title(): void
    {
        $provider = $this->provider([
            '?s=' => $this->searchPage(),
            '/anime/sousou-no-frieren/' => $this->seriesPage(),
        ]);

        $episodes = $provider->episodesFor([
            'title' => 'Sousou no Frieren',
            'altTitles' => ['Frieren: Beyond Journey\'s End'],
        ]);

        $this->assertCount(3, $episodes);
        $this->assertSame('sd|sousou-no-frieren|1', $episodes[0]['id']);
        $this->assertSame('sd|sousou-no-frieren|10', $episodes[2]['id']);
    }

    public function test_it_picks_the_season_that_matches_the_title(): void
    {
        $provider = $this->provider([
            '?s=' => $this->searchPage(),
            '/anime/sousou-no-frieren-season-2/' => $this->seasonTwoPage(),
            '/anime/sousou-no-frieren/' => $this->seriesPage(),
        ]);

        $episodes = $provider->episodesFor([
            'title' => 'Sousou no Frieren 2nd Season',
            'altTitles' => ['Sousou no Frieren Season 2'],
        ]);

        $this->assertSame('sd|sousou-no-frieren-season-2|1', $episodes[0]['id']);
    }

    public function test_it_ignores_a_generic_season_match_from_another_show(): void
    {
        // Searching "season 2" lists Black Clover; that hit must never beat the
        // real series just because it shares the release words.
        $provider = $this->provider([
            '?s=' => $this->searchPage(),
            '/anime/black-clover-season-2/' => '<div><a href="https://v2.samehadaku.how/black-clover-season-2-episode-1/">1</a></div>',
            '/anime/sousou-no-frieren-season-2/' => $this->seasonTwoPage(),
            '/anime/sousou-no-frieren/' => $this->seriesPage(),
        ]);

        $episodes = $provider->episodesFor([
            'title' => 'Sousou no Frieren 2nd Season',
            'altTitles' => ['Sousou no Frieren Season 2'],
        ]);

        $this->assertNotEmpty($episodes);
        $this->assertSame('sd|sousou-no-frieren-season-2|1', $episodes[0]['id']);
    }

    public function test_it_returns_the_best_mp4_first(): void
    {
        $provider = $this->provider([
            '/anime/sousou-no-frieren/' => $this->seriesPage(),
            '/sousou-no-frieren-episode-10-end/' => $this->episodePage(),
        ]);

        $stream = $provider->streamFor('sd|sousou-no-frieren|10');

        $this->assertNotNull($stream);
        $this->assertSame('mp4', $stream['kind']);
        $this->assertSame('Samehadaku', $stream['provider']);
        $this->assertSame('1080p', $stream['sources'][0]['quality']);
        $this->assertStringContainsString('FULLHD', $stream['sources'][0]['url']);
    }

    public function test_it_falls_back_to_an_embed_when_no_mp4_exists(): void
    {
        $provider = $this->provider([
            '?s=' => $this->searchPage(),
            '/anime/sousou-no-frieren/' => $this->seriesPage(),
            '/sousou-no-frieren-episode-1/' => <<<'HTML'
<iframe src="https://api.wibufile.com/embed/05a7eb99-58d0-45e1-8422-83485b9b439d"></iframe>
HTML,
        ]);

        $stream = $provider->streamFor('sd|sousou-no-frieren|1');

        $this->assertNotNull($stream);
        $this->assertSame('embed', $stream['kind']);
        $this->assertStringContainsString('wibufile.com/embed/', $stream['embed']);
    }

    public function test_it_fails_soft_for_unknown_titles(): void
    {
        $provider = $this->provider(['?s=' => '<html>No results</html>']);

        $this->assertSame([], $provider->episodesFor(['title' => 'Nothing Here']));
        $this->assertNull($provider->streamForEpisode(['title' => 'Nothing Here'], 1));
        $this->assertNull($provider->streamFor('sd|missing|1'));
    }

    public function test_it_does_not_hijack_titles_on_a_single_weak_common_word(): void
    {
        $provider = $this->provider([
            '?s=' => '<a href="https://v2.samehadaku.how/high-school-dxd-hero-episode-1/">1</a>',
            '/anime/high-school-dxd-hero/' => '<a href="https://v2.samehadaku.how/high-school-dxd-hero-episode-1/">1</a>',
        ]);

        $this->assertSame([], $provider->episodesFor([
            'title' => 'Daily Lives of High School Boys',
            'altTitles' => ['Danshi Koukousei no Nichijou'],
        ]));
    }

    public function test_it_extracts_direct_mp4_from_blogger_embeds(): void
    {
        $bloggerHtml = 'data-p="{\"streamingData\":{\"formats\":[{\"itag\":18,\"url\":\"https://rr1---sn.googlevideo.com/videoplayback?id=xyz\\u0026mime=video/mp4\",\"qualityLabel\":\"360p\"}]}}"';

        $provider = $this->provider([
            '/anime/sousou-no-frieren/' => $this->seriesPage(),
            '/sousou-no-frieren-episode-1/' => '<iframe src="https://www.blogger.com/video.g?token=AD6v5tok"></iframe>',
            'blogger.com/video.g' => $bloggerHtml,
        ]);

        $stream = $provider->streamFor('sd|sousou-no-frieren|1');

        $this->assertNotNull($stream);
        $this->assertSame('mp4', $stream['kind']);
        $this->assertTrue($stream['proxy_file']);
        $this->assertStringContainsString('googlevideo.com/videoplayback', $stream['sources'][0]['url']);
    }
}
