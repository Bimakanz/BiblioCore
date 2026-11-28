<?php

namespace Tests\Feature;

use App\Services\Anime\AnimeExtraSources;
use App\Services\Anime\AnimeService;
use App\Services\Anime\AnimeStreamProvider;
use App\Services\Anime\KuramanimeSource;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class KuramanimeSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * @param  array<string, string>  $fixtures  url fragment => body
     * @param  array<string, array<string, mixed>>  $workerFixtures  episode url fragment => worker json
     */
    protected function provider(array $fixtures, array $workerFixtures = [], ?string $workerUrl = 'http://127.0.0.1:4100'): KuramanimeSource
    {
        return new class($fixtures, $workerFixtures, $workerUrl) extends KuramanimeSource
        {
            /**
             * @param  array<string, string>  $fixtures
             * @param  array<string, array<string, mixed>>  $workerFixtures
             */
            public function __construct(
                private array $fixtures,
                private array $workerFixtures,
                ?string $workerUrl
            ) {
                parent::__construct();
                $this->workerUrl = $workerUrl;
                $this->configuredBase = 'https://v20.kuramanime.ing';
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

            protected function fetchWorker(string $episodeUrl): ?array
            {
                foreach ($this->workerFixtures as $needle => $payload) {
                    if (str_contains($episodeUrl, $needle)) {
                        return $payload;
                    }
                }

                return null;
            }
        };
    }

    protected function quicksearchPayload(): string
    {
        return json_encode([
            'html' => <<<'HTML'
<div id="topSearchResultList" class="search__result__main">
  <div class="search__result__list">
    <a href="https://v20.kuramanime.ing/anime/4468/sousou-no-frieren-2nd-season" class="search__result__anchor">
      <div class="search__result__text"><h5 class="title">Sousou no Frieren 2nd Season</h5></div>
    </a>
  </div>
  <div class="search__result__list">
    <a href="https://v20.kuramanime.ing/anime/2213/sousou-no-frieren-zQFI1c5G" class="search__result__anchor">
      <div class="search__result__text"><h5 class="title">Sousou no Frieren</h5></div>
    </a>
  </div>
</div>
HTML,
        ], JSON_THROW_ON_ERROR);
    }

    protected function seriesPage(): string
    {
        return <<<'HTML'
<a id="episodeLists" data-content="
  <a class='btn' href='https://v20.kuramanime.ing/anime/2213/sousou-no-frieren-zQFI1c5G/episode/1'>Ep 1 (Terlama)</a>
  <a class='btn' href='https://v20.kuramanime.ing/anime/2213/sousou-no-frieren-zQFI1c5G/episode/2'>Ep 2 (Terbaru)</a>
"></a>
HTML;
    }

    protected function paginatedSeriesPage(): string
    {
        return <<<'HTML'
<a id="episodeLists" data-content="
  <a class='btn' href='https://v20.kuramanime.ing/anime/50/one-piece-OreGjicNb0Fh/episode/989'>Ep 989 (Terlama)</a>
  <a class='btn' href='https://v20.kuramanime.ing/anime/50/one-piece-OreGjicNb0Fh/episode/1180'>Ep 1180 (Terbaru)</a>
"></a>
HTML;
    }

    public function test_it_is_disabled_when_worker_url_is_missing(): void
    {
        $provider = $this->provider([], [], null);

        $this->assertFalse($provider->enabled());
        $this->assertSame([], $provider->episodesFor(['title' => 'Sousou no Frieren']));
        $this->assertNull($provider->streamFor('km|2213|sousou-no-frieren-zQFI1c5G|1'));
    }

    public function test_it_resolves_episodes_from_quicksearch_and_picks_right_season(): void
    {
        $provider = $this->provider([
            '/quicksearch/get' => $this->quicksearchPayload(),
            '/anime/2213/sousou-no-frieren-zQFI1c5G' => $this->seriesPage(),
        ]);

        $episodes = $provider->episodesFor([
            'title' => 'Sousou no Frieren',
            'altTitles' => ["Frieren: Beyond Journey's End"],
        ]);

        $this->assertCount(2, $episodes);
        $this->assertSame('km|2213|sousou-no-frieren-zQFI1c5G|1', $episodes[0]['id']);
        $this->assertSame('km|2213|sousou-no-frieren-zQFI1c5G|2', $episodes[1]['id']);
    }

    public function test_it_supports_paginated_long_running_series_for_earlier_episodes(): void
    {
        $provider = $this->provider([
            '/quicksearch/get' => json_encode([
                'html' => '<a href="https://v20.kuramanime.ing/anime/50/one-piece-OreGjicNb0Fh"><h5 class="title">One Piece</h5></a>',
            ], JSON_THROW_ON_ERROR),
            '/anime/50/one-piece-OreGjicNb0Fh' => $this->paginatedSeriesPage(),
        ], [
            '/anime/50/one-piece-OreGjicNb0Fh/episode/1' => [
                'ok' => true,
                'kind' => 'mp4',
                'sources' => [
                    ['url' => 'https://komari.my.id/kdrive/abc/op-01-720p.mp4', 'quality' => '720p', 'isM3U8' => false],
                ],
                'referer' => 'https://v20.kuramanime.ing/anime/50/one-piece-OreGjicNb0Fh/episode/1',
            ],
        ]);

        $stream = $provider->streamForEpisode(['title' => 'One Piece'], 1);

        $this->assertNotNull($stream);
        $this->assertSame('mp4', $stream['kind']);
        $this->assertSame('Kuramanime', $stream['provider']);
        $this->assertSame('id', $stream['hard_sub_lang']);
        $this->assertTrue($stream['proxy_file']);
        $this->assertSame('720p', $stream['sources'][0]['quality']);
    }

    public function test_it_fills_paginated_gap_between_first_page_and_latest_badge(): void
    {
        $pageOneWithGap = <<<'HTML'
<a id="episodeLists" data-content="
  <a class='btn' href='https://v20.kuramanime.ing/anime/2213/sousou-no-frieren-zQFI1c5G/episode/1'>Ep 1 (Terlama)</a>
  <a class='btn' href='https://v20.kuramanime.ing/anime/2213/sousou-no-frieren-zQFI1c5G/episode/28'>Ep 28 (Terbaru)</a>
  <a class='btn' href='https://v20.kuramanime.ing/anime/2213/sousou-no-frieren-zQFI1c5G/episode/2'>Ep 2</a>
"></a>
HTML;

        $provider = $this->provider([
            '/quicksearch/get' => $this->quicksearchPayload(),
            '/anime/2213/sousou-no-frieren-zQFI1c5G' => $pageOneWithGap,
        ]);

        $episodes = $provider->episodesFor(['title' => 'Sousou no Frieren']);

        $this->assertCount(28, $episodes);
        $this->assertSame('km|2213|sousou-no-frieren-zQFI1c5G|14', $episodes[13]['id']);
        $this->assertSame('km|2213|sousou-no-frieren-zQFI1c5G|28', $episodes[27]['id']);
    }

    public function test_it_rejects_non_mp4_worker_responses(): void
    {
        $provider = $this->provider([], [
            '/episode/1' => [
                'ok' => true,
                'kind' => 'hls',
                'sources' => [
                    ['url' => 'https://example.com/playlist.m3u8', 'quality' => 'auto', 'isM3U8' => true],
                ],
            ],
        ]);

        $this->assertNull($provider->streamFor('km|2213|sousou-no-frieren-zQFI1c5G|1'));
    }

    public function test_stream_provider_walks_nimegami_kuramanime_then_samehadaku_for_id_mode(): void
    {
        Cache::put('anime_titles_25', ['Some Show'], 3600);

        $extra = new class extends AnimeExtraSources
        {
            public function nimegamiStreamFor(array $anime, int $number): ?array
            {
                return null;
            }

            public function animasuStreamFor(array $anime, int $number): ?array
            {
                return null;
            }

            public function kuramanimeStreamFor(array $anime, int $number): ?array
            {
                return [
                    'sources' => [['url' => 'https://komari.my.id/kdrive/xyz/show-1-720p.mp4', 'quality' => '720p']],
                    'subtitles' => [],
                    'kind' => 'mp4',
                    'provider' => 'Kuramanime',
                    'hard_sub' => true,
                    'hard_sub_lang' => 'id',
                    'proxy_file' => true,
                ];
            }

            public function samehadakuStreamFor(array $anime, int $number): ?array
            {
                return null;
            }
        };

        $stream = (new AnimeStreamProvider($extra))->getStream('watch/kaa/25/sub/kaa-1', 'id');

        $this->assertNotNull($stream);
        $this->assertSame('Kuramanime', $stream['provider']);
        $this->assertSame('id', $stream['hard_sub_lang']);
    }

    public function test_stream_provider_routes_km_prefix_back_to_kuramanime(): void
    {
        $extra = new class extends AnimeExtraSources
        {
            public function kuramanimeStream(string $reference): ?array
            {
                return [
                    'sources' => [['url' => 'https://googleapis.amiya.my.id/kdrive/xyz/ep1.mp4', 'quality' => '720p']],
                    'subtitles' => [],
                    'kind' => 'mp4',
                    'provider' => 'Kuramanime',
                    'hard_sub' => true,
                    'hard_sub_lang' => 'id',
                    'proxy_file' => true,
                ];
            }
        };

        $stream = (new AnimeStreamProvider($extra))->getStream('km|2213|sousou-no-frieren-zQFI1c5G|1');

        $this->assertNotNull($stream);
        $this->assertSame('Kuramanime', $stream['provider']);
        $this->assertSame('mp4', $stream['kind']);
    }

    public function test_kuramanime_mp4_is_served_through_the_file_proxy(): void
    {
        $this->mock(AnimeService::class, function ($mock) {
            $mock->shouldReceive('markAvailable')->andReturnNull();
        });

        $this->mock(AnimeStreamProvider::class, function ($mock) {
            $mock->shouldReceive('getStream')->once()->andReturn([
                'provider' => 'Kuramanime',
                'referer' => 'https://v20.kuramanime.ing/anime/2213/sousou-no-frieren-zQFI1c5G/episode/1',
                'sources' => [['url' => 'https://googleapis.amiya.my.id/kdrive/abc/ep1.mp4', 'quality' => '720p']],
                'subtitles' => [],
                'kind' => 'mp4',
                'embed' => null,
                'intro' => null,
                'outro' => null,
                'hard_sub' => true,
                'hard_sub_lang' => 'id',
                'proxy_file' => true,
            ]);
            $mock->shouldReceive('servers')->andReturn([]);
            $mock->shouldReceive('modes')->andReturn([]);
        });

        $response = $this->getJson('/anime/al_1/stream.json?ep=watch%2Fkaa%2F1%2Fsub%2Fkaa-1&server=kuramanime');

        $response->assertOk()
            ->assertJsonPath('source.type', 'mp4')
            ->assertJsonPath('provider', 'Kuramanime')
            ->assertJsonPath('hardSub', true)
            ->assertJsonPath('hardSubLang', 'id');

        $this->assertStringContainsString('/anime/proxy/file?t=', (string) $response->json('source.url'));
    }
}
