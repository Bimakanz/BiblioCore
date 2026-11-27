<?php

namespace Tests\Feature;

use App\Services\Anime\AnimeExtraSources;
use App\Services\Anime\AnimeService;
use App\Services\Anime\AnimeStreamProvider;
use App\Services\Anime\NimegamiSource;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NimegamiSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * @param  array<string, string>  $fixtures  url fragment => body
     */
    protected function provider(array $fixtures): NimegamiSource
    {
        return new class($fixtures) extends NimegamiSource
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
<div class="grid">
<a class="card-title" href="/sousou-no-frieren-season-2-sub-indo/">Sousou no Frieren 2nd Season</a>
<a class="card-title" href="/sousou-no-frieren-sub-indo/">Sousou no Frieren</a>
</div>
HTML;
    }

    protected function seriesPage(): string
    {
        return <<<'HTML'
<script>self.__next_f.push([1,"{\"servers\":[\"/sousou-no-frieren-sub-indo/streaming/1/360p/aaa111/\",\"/sousou-no-frieren-sub-indo/streaming/1/720p/bbb222/\",\"/sousou-no-frieren-sub-indo/streaming/2/480p/ccc333/?player=worker\",\"/sousou-no-frieren-sub-indo/streaming/2/720p/ddd444/\"]}"])</script>
HTML;
    }

    protected function migratedPage(): string
    {
        return <<<'HTML'
<div class="download-subsection download-per-episode" id="download_episode_2">
  <div class="download-subheading"><h3>Episode 2</h3></div>
  <div class="download-box"><div class="download-rows">
    <div class="download-row">
      <div class="quality-label"><strong>720p</strong><span>HD</span></div>
      <div class="download-mirrors">
        <a href="/jujutsu-kaisen-sub-indo/download/e8b065373e7ef2fe57f2/" title="SR Utama [BC 2] - pilihan 1">SR Utama</a>
      </div>
    </div>
  </div></div>
</div>
HTML;
    }

    public function test_it_resolves_episodes_and_picks_the_right_season(): void
    {
        $provider = $this->provider([
            '/anime/?q=' => $this->searchPage(),
            '/sousou-no-frieren-sub-indo/' => $this->seriesPage(),
        ]);

        $episodes = $provider->episodesFor([
            'title' => 'Sousou no Frieren',
            'altTitles' => ['Frieren: Beyond Journey\'s End'],
        ]);

        $this->assertCount(2, $episodes);
        $this->assertSame('ng|sousou-no-frieren-sub-indo|1', $episodes[0]['id']);
        $this->assertSame('ng|sousou-no-frieren-sub-indo|2', $episodes[1]['id']);
    }

    public function test_it_prefers_the_best_quality_primary_stream(): void
    {
        $provider = $this->provider([
            '/sousou-no-frieren-sub-indo/streaming/1/720p/bbb222/' => '<main id="player" data-embedded="0" data-src="https://stream-stor.berkasdrive.com/v/v1.abc&amp;token=1"></main>',
            '/sousou-no-frieren-sub-indo/' => $this->seriesPage(),
        ]);

        $stream = $provider->streamFor('ng|sousou-no-frieren-sub-indo|1');

        $this->assertNotNull($stream);
        $this->assertSame('mp4', $stream['kind']);
        $this->assertSame('Nimegami', $stream['provider']);
        $this->assertSame('id', $stream['hard_sub_lang']);
        $this->assertSame('720p', $stream['sources'][0]['quality']);
        $this->assertSame('https://stream-stor.berkasdrive.com/v/v1.abc&token=1', $stream['sources'][0]['url']);
    }

    public function test_it_supports_migrated_download_rows_via_worker_player(): void
    {
        $provider = $this->provider([
            '/jujutsu-kaisen-sub-indo/streaming/2/720p/e8b065373e7ef2fe57f2/?player=worker' => '<main id="player" data-embedded="0" data-src="https://workers-anime.berkasdrive.com/download/video.mp4?_nimegami_inline=1"></main>',
            '/jujutsu-kaisen-sub-indo/' => $this->migratedPage(),
        ]);

        $stream = $provider->streamFor('ng|jujutsu-kaisen-sub-indo|2');

        $this->assertNotNull($stream);
        $this->assertSame('mp4', $stream['kind']);
        $this->assertStringContainsString('workers-anime.berkasdrive.com', $stream['sources'][0]['url']);
    }

    public function test_it_skips_embedded_players_when_looking_for_a_direct_mp4(): void
    {
        $provider = $this->provider([
            '/sousou-no-frieren-sub-indo/streaming/1/720p/bbb222/' => '<main id="player" data-embedded="1" data-src="https://example.com/embed"></main>',
            '/sousou-no-frieren-sub-indo/streaming/1/360p/aaa111/' => '<main id="player" data-embedded="0" data-src="https://stream-stor.berkasdrive.com/v/v1.360p"></main>',
            '/sousou-no-frieren-sub-indo/' => $this->seriesPage(),
        ]);

        $stream = $provider->streamFor('ng|sousou-no-frieren-sub-indo|1');

        $this->assertNotNull($stream);
        $this->assertSame('360p', $stream['sources'][0]['quality']);
        $this->assertSame('https://stream-stor.berkasdrive.com/v/v1.360p', $stream['sources'][0]['url']);
    }

    public function test_stream_provider_walks_nimegami_then_samehadaku_for_indonesian_mode(): void
    {
        Cache::put('anime_titles_12', ['Some Show'], 3600);

        $extra = new class extends AnimeExtraSources
        {
            public function nimegamiStreamFor(array $anime, int $number): ?array
            {
                return null;
            }

            public function kuramanimeStreamFor(array $anime, int $number): ?array
            {
                return null;
            }

            public function samehadakuStreamFor(array $anime, int $number): ?array
            {
                return [
                    'sources' => [['url' => 'https://s0.wibufile.com/video01/show-1.mp4', 'quality' => '720p']],
                    'subtitles' => [],
                    'kind' => 'mp4',
                    'provider' => 'Samehadaku',
                    'hard_sub' => true,
                    'hard_sub_lang' => 'id',
                ];
            }
        };

        $stream = (new AnimeStreamProvider($extra))->getStream('watch/kaa/12/sub/kaa-1', 'id');

        $this->assertNotNull($stream);
        $this->assertSame('Samehadaku', $stream['provider']);
        $this->assertSame('id', $stream['hard_sub_lang']);
    }

    public function test_berkasdrive_mp4_is_served_through_the_file_proxy(): void
    {
        $this->mock(AnimeService::class, function ($mock) {
            $mock->shouldReceive('markAvailable')->andReturnNull();
        });

        $this->mock(AnimeStreamProvider::class, function ($mock) {
            $mock->shouldReceive('getStream')->once()->andReturn([
                'provider' => 'Nimegami',
                'referer' => 'https://nimegami.id/',
                'sources' => [['url' => 'https://stream-stor.berkasdrive.com/v/v1.token', 'quality' => '720p']],
                'subtitles' => [],
                'kind' => 'mp4',
                'embed' => null,
                'intro' => null,
                'outro' => null,
                'hard_sub' => true,
                'hard_sub_lang' => 'id',
            ]);
            $mock->shouldReceive('servers')->andReturn([]);
            $mock->shouldReceive('modes')->andReturn([]);
        });

        $response = $this->getJson('/anime/al_1/stream.json?ep=watch%2Fkaa%2F1%2Fsub%2Fkaa-1&server=id');

        $response->assertOk()
            ->assertJsonPath('source.type', 'mp4')
            ->assertJsonPath('provider', 'Nimegami')
            ->assertJsonPath('hardSub', true)
            ->assertJsonPath('hardSubLang', 'id');

        $this->assertStringContainsString('/anime/proxy/file?t=', (string) $response->json('source.url'));
    }
}
