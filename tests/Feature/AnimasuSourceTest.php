<?php

namespace Tests\Feature;

use App\Services\Anime\AnimasuSource;
use App\Services\Anime\AnimeExtraSources;
use App\Services\Anime\AnimeService;
use App\Services\Anime\AnimeStreamProvider;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AnimasuSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * @param  array<string, string>  $fixtures  url fragment => body
     */
    protected function provider(array $fixtures): AnimasuSource
    {
        return new class($fixtures) extends AnimasuSource
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
<div class="listupd">
  <a href="https://animasu.love/anime/danshi-koukousei-no-nichijou-specials/" title="Nonton Anime Daily Lives of High School Boys Specials">Specials</a>
  <a href="https://animasu.love/anime/danshi-koukousei-no-nichijou/" title="Nonton Anime Daily Lives of High School Boys">TV</a>
</div>
HTML;
    }

    protected function seriesPage(): string
    {
        return <<<'HTML'
<ul id="daftarepisode">
  <li><a href="https://animasu.love/nonton-danshi-koukousei-no-nichijou-episode-11/">Ep 11</a></li>
  <li><a href="https://animasu.love/nonton-danshi-koukousei-no-nichijou-episode-12/">Ep 12</a></li>
</ul>
HTML;
    }

    protected function episodePageWithYourUploadAndBlogger(): string
    {
        $yu = base64_encode('<iframe src="https://www.yourupload.com/embed/GyTmM8su1sVl"></iframe>');
        $bg = base64_encode('<iframe src="https://www.blogger.com/video.g?token=AD6v5testToken123"></iframe>');

        return <<<HTML
<select class="mirror">
  <option value="{$yu}">720p [1]</option>
  <option value="{$bg}">480p [1]</option>
</select>
HTML;
    }

    public function test_it_resolves_series_and_avoids_specials(): void
    {
        $provider = $this->provider([
            '/?s=' => $this->searchPage(),
            '/anime/danshi-koukousei-no-nichijou/' => $this->seriesPage(),
        ]);

        $episodes = $provider->episodesFor([
            'title' => 'Daily Lives of High School Boys',
            'altTitles' => ['Danshi Koukousei no Nichijou'],
        ]);

        $this->assertCount(2, $episodes);
        $this->assertSame('am|danshi-koukousei-no-nichijou|11', $episodes[0]['id']);
        $this->assertSame('am|danshi-koukousei-no-nichijou|12', $episodes[1]['id']);
    }

    public function test_it_extracts_yourupload_and_blogger_mp4_streams(): void
    {
        $yourUploadHtml = "<script>jwplayerOptions = { file: 'https://vidcache.net:8161/abc/video.mp4' };</script>";
        $bloggerHtml = 'data-p="{\"streamingData\":{\"formats\":[{\"itag\":18,\"url\":\"https://rr1---sn.googlevideo.com/videoplayback?id=123\\u0026mime=video/mp4\",\"qualityLabel\":\"360p\"}]}}"';

        $provider = $this->provider([
            '/anime/danshi-koukousei-no-nichijou/' => $this->seriesPage(),
            '/nonton-danshi-koukousei-no-nichijou-episode-11/' => $this->episodePageWithYourUploadAndBlogger(),
            'yourupload.com/embed/' => $yourUploadHtml,
            'blogger.com/video.g' => $bloggerHtml,
        ]);

        $stream = $provider->streamFor('am|danshi-koukousei-no-nichijou|11');

        $this->assertNotNull($stream);
        $this->assertSame('mp4', $stream['kind']);
        $this->assertSame('Animasu', $stream['provider']);
        $this->assertSame('id', $stream['hard_sub_lang']);
        $this->assertTrue($stream['proxy_file']);
        $this->assertCount(2, $stream['sources']);
        $this->assertSame('720p', $stream['sources'][0]['quality']);
        $this->assertStringContainsString('vidcache.net:8161', $stream['sources'][0]['url']);
        $this->assertSame('https://www.yourupload.com/', $stream['sources'][0]['referer']);
        $this->assertStringContainsString('googlevideo.com/videoplayback', $stream['sources'][1]['url']);
    }

    public function test_stream_provider_walks_nimegami_then_animasu_for_indonesian_mode(): void
    {
        Cache::put('anime_titles_11843', ['Daily Lives of High School Boys'], 3600);

        $extra = new class extends AnimeExtraSources
        {
            public function nimegamiStreamFor(array $anime, int $number): ?array
            {
                return null;
            }

            public function animasuStreamFor(array $anime, int $number): ?array
            {
                return [
                    'sources' => [['url' => 'https://rr1---sn.googlevideo.com/videoplayback?id=1', 'quality' => '360p', 'referer' => null]],
                    'subtitles' => [],
                    'kind' => 'mp4',
                    'provider' => 'Animasu',
                    'hard_sub' => true,
                    'hard_sub_lang' => 'id',
                    'proxy_file' => true,
                ];
            }
        };

        $stream = (new AnimeStreamProvider($extra))->getStream('watch/anikoto/11843/sub/anikoto-11', 'id');

        $this->assertNotNull($stream);
        $this->assertSame('Animasu', $stream['provider']);
        $this->assertSame('id', $stream['hard_sub_lang']);
    }

    public function test_animasu_mp4_is_served_through_the_file_proxy(): void
    {
        $this->mock(AnimeService::class, function ($mock) {
            $mock->shouldReceive('markAvailable')->andReturnNull();
        });

        $this->mock(AnimeStreamProvider::class, function ($mock) {
            $mock->shouldReceive('getStream')->once()->andReturn([
                'provider' => 'Animasu',
                'referer' => null,
                'sources' => [[
                    'url' => 'https://vidcache.net:8161/abc/video.mp4',
                    'quality' => '720p',
                    'referer' => 'https://www.yourupload.com/',
                ]],
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

        $response = $this->getJson('/anime/al_11843/stream.json?ep=watch%2Fanikoto%2F11843%2Fsub%2Fanikoto-11&n=11&server=animasu');

        $response->assertOk()
            ->assertJsonPath('source.type', 'mp4')
            ->assertJsonPath('provider', 'Animasu')
            ->assertJsonPath('hardSub', true)
            ->assertJsonPath('hardSubLang', 'id');

        $this->assertStringContainsString('/anime/proxy/file?t=', (string) $response->json('source.url'));
    }
}
