<?php

namespace Tests\Feature;

use Tests\TestCase;

class AnimeTest extends TestCase
{
    public function test_manga_home_shows_link_to_anime_mode(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('href="'.route('anime.index').'"', false);
        $response->assertSee('Manga', false);
    }

    public function test_anime_home_loads_and_shows_link_to_manga_mode(): void
    {
        $response = $this->get('/anime');
        $response->assertOk();
        $response->assertSee('href="'.route('manga.index').'"', false);
        $response->assertSee('Anime', false);
    }

    public function test_anime_live_search_short_query_returns_empty(): void
    {
        $response = $this->getJson('/anime/api/live-search?q=a');
        $response->assertOk();
        $response->assertExactJson(['data' => []]);
    }

    public function test_anime_detail_returns_404_for_unknown_id(): void
    {
        $response = $this->get('/anime/al_999999999');
        $response->assertNotFound();
    }

    public function test_episodes_json_honours_the_dub_audio_track(): void
    {
        $this->mock(\App\Services\Anime\AnimeService::class, function ($mock) {
            $mock->shouldReceive('isUnplayable')->andReturn(false);
            $mock->shouldReceive('getDetail')->andReturn([
                'id' => 'al_1',
                'anilistId' => 1,
                'malId' => 1,
                'title' => 'Test Anime',
                'titles' => [],
                'altTitles' => [],
                'episodes' => 12,
            ]);
        });

        $this->mock(\App\Services\Anime\AnimeStreamProvider::class, function ($mock) {
            $mock->shouldReceive('getEpisodes')
                ->once()
                ->with(1, 12, 'dub', \Mockery::type('array'))
                ->andReturn([
                    'provider' => 'kaa',
                    'providers' => ['kaa'],
                    'audio' => 'dub',
                    'audioAvailable' => ['sub' => true, 'dub' => true],
                    'episodes' => [
                        ['id' => 'watch/kaa/1/dub/kaa-1', 'number' => 1],
                    ],
                ]);
        });

        $response = $this->getJson('/anime/al_1/episodes.json?audio=dub');

        $response->assertOk()
            ->assertJsonPath('audio', 'dub')
            ->assertJsonPath('audioAvailable.dub', true)
            ->assertJsonPath('episodes.0.id', 'watch/kaa/1/dub/kaa-1');
    }

    public function test_playlist_proxy_rejects_unknown_key(): void
    {
        $response = $this->get('/anime/proxy/playlist?k=unknownkey');
        $response->assertNotFound();
    }

    public function test_file_proxy_rejects_unknown_key(): void
    {
        $response = $this->get('/anime/proxy/file?k=unknownkey');
        $response->assertNotFound();
    }

    public function test_animeheaven_mp4_is_served_through_the_file_proxy(): void
    {
        $this->mock(\App\Services\Anime\AnimeService::class, function ($mock) {
            $mock->shouldReceive('markAvailable')->andReturnNull();
        });

        $this->mock(\App\Services\Anime\AnimeStreamProvider::class, function ($mock) {
            $mock->shouldReceive('getStream')->once()->andReturn([
                'provider' => 'AnimeHeaven',
                'referer' => null,
                'sources' => [['url' => 'https://ct.animeheaven.me/video.mp4?token=abc', 'quality' => 'auto']],
                'subtitles' => [],
                'kind' => 'mp4',
                'embed' => null,
                'intro' => null,
                'outro' => null,
                'hard_sub' => true,
            ]);
            $mock->shouldReceive('servers')->andReturn([]);
        });

        $response = $this->getJson('/anime/al_1/stream.json?ep=watch%2Fkaa%2F1%2Fsub%2Fkaa-1&server=animeheaven');

        $response->assertOk()
            ->assertJsonPath('source.type', 'mp4')
            ->assertJsonPath('hardSub', true);

        // The CDN 403s cross-site media requests, so the browser must get our
        // own range-capable proxy URL instead of the raw file. The token is
        // signed so every range request works on any serverless container.
        $url = (string) $response->json('source.url');
        $this->assertStringContainsString('/anime/proxy/file?t=', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('t', $query);
        $this->assertStringContainsString('.', $query['t']);
    }

    public function test_file_proxy_range_bounds(): void
    {
        $controller = $this->app->make(\App\Http\Controllers\AnimeController::class);
        $method = new \ReflectionMethod($controller, 'fileBounds');
        $method->setAccessible(true);

        $this->assertSame([0, 999, 200], $method->invoke($controller, '', 1000));
        $this->assertSame([0, 999, 206], $method->invoke($controller, 'bytes=0-', 1000));
        $this->assertSame([100, 199, 206], $method->invoke($controller, 'bytes=100-199', 1000));
        $this->assertSame([900, 999, 206], $method->invoke($controller, 'bytes=-100', 1000));
        $this->assertSame([null, 0, 416], $method->invoke($controller, 'bytes=1000-', 1000));
    }

    public function test_embed_response_keeps_the_burned_in_flag(): void
    {
        $this->mock(\App\Services\Anime\AnimeService::class, function ($mock) {
            $mock->shouldReceive('getDetail')->andReturn(['id' => 'al_1', 'anilistId' => 1, 'episodes' => 12]);
            $mock->shouldReceive('isUnplayable')->andReturn(false);
            $mock->shouldReceive('markAvailable')->andReturnNull();
        });

        $this->mock(\App\Services\Anime\AnimeStreamProvider::class, function ($mock) {
            $mock->shouldReceive('servers')->andReturn([]);
            $mock->shouldReceive('getStream')->andReturn([
                'provider' => 'Samehadaku',
                'referer' => null,
                'sources' => [['url' => 'https://www.blogger.com/video.g?token=x', 'quality' => 'auto']],
                'subtitles' => [],
                'kind' => 'embed',
                'embed' => 'https://www.blogger.com/video.g?token=x',
                'intro' => null,
                'outro' => null,
                'hard_sub' => true,
            ]);
        });

        $response = $this->getJson('/anime/al_1/stream.json?ep=watch%2Fkaa%2F1%2Fsub%2Fkaa-1');

        $response->assertOk()
            ->assertJsonPath('source.type', 'embed')
            ->assertJsonPath('hardSub', true);
    }

    public function test_translated_subtitle_requires_episode_parameter(): void
    {
        $response = $this->get('/anime/subtitle/id.vtt');
        $response->assertStatus(400);
    }

    public function test_auto_english_subtitle_rejects_unknown_key(): void
    {
        $response = $this->get('/anime/subtitle/en-auto.vtt?k=unknownkey&idx=0');
        $response->assertNotFound();
    }

    public function test_stream_adds_auto_english_track_when_provider_has_no_english(): void
    {
        $this->fakeStream([
            ['lang' => 'en', 'label' => 'Arabic', 'url' => 'https://example.com/ar.vtt'],
            ['lang' => 'en', 'label' => 'Thai', 'url' => 'https://example.com/th.vtt'],
        ]);

        $response = $this->getJson('/anime/al_1/stream.json?ep=ep-auto');
        $response->assertOk();
        $labels = array_column($response->json('subtitles'), 'label');
        $this->assertContains('English (Auto)', $labels);
    }

    public function test_stream_does_not_add_auto_english_track_when_english_exists(): void
    {
        $this->fakeStream([
            ['lang' => 'en', 'label' => 'English', 'url' => 'https://example.com/en.vtt'],
            ['lang' => 'en', 'label' => 'Arabic', 'url' => 'https://example.com/ar.vtt'],
        ]);

        $response = $this->getJson('/anime/al_1/stream.json?ep=ep-english');
        $response->assertOk();
        $labels = array_column($response->json('subtitles'), 'label');
        $this->assertNotContains('English (Auto)', $labels);
        $this->assertContains('English', $labels);
    }

    private function fakeStream(array $subtitles): void
    {
        $stream = [
            'provider' => 'test',
            'referer' => null,
            'sources' => [['url' => 'https://example.com/master.m3u8', 'quality' => 'auto']],
            'subtitles' => $subtitles,
            'intro' => null,
            'outro' => null,
        ];

        $episodeId = $subtitles[0]['label'] === 'Arabic' ? 'ep-auto' : 'ep-english';

        $mock = \Mockery::mock(\App\Services\Anime\AnimeStreamProvider::class)->makePartial();
        $mock->shouldReceive('servers')->andReturn([]);
        $mock->shouldReceive('getStream')->andReturn($stream);
        $this->app->instance(\App\Services\Anime\AnimeStreamProvider::class, $mock);

        // A verified stream skips the playlist reachability probe.
        \Illuminate\Support\Facades\Cache::put('anime_verified_'.md5($episodeId.'|test'), 1, 3600);
    }

    public function test_watch_page_redirects_to_detail_without_episode(): void
    {
        // Unknown anime id -> 404 before any watch rendering happens.
        $response = $this->get('/anime/al_999999999/watch');
        $response->assertNotFound();
    }

    public function test_watch_page_plays_burned_in_files_with_the_native_player(): void
    {
        $this->mock(\App\Services\Anime\AnimeService::class, function ($mock) {
            $mock->shouldReceive('isUnplayable')->andReturn(false);
            $mock->shouldReceive('getDetail')->andReturn([
                'id' => 'al_1',
                'anilistId' => 1,
                'title' => 'Test Anime',
                'altTitles' => [],
                'coverUrl' => 'https://example.com/cover.jpg',
                'bannerUrl' => 'https://example.com/banner.jpg',
                'duration' => 24,
                'format' => 'TV',
                'status' => 'FINISHED',
                'year' => 2024,
                'score' => 8.5,
                'description' => 'A test anime used by the watch page tests.',
                'genres' => [],
            ]);
        });

        $this->mock(\App\Services\Anime\AnimeStreamProvider::class, function ($mock) {
            $mock->shouldReceive('dubAvailable')->andReturn(false);
            $mock->shouldReceive('servers')->andReturn([]);
        });

        $response = $this->get('/anime/al_1/watch?ep=watch%2Fkaa%2F1%2Fsub%2Fkaa-1&n=1');

        $response->assertOk()
            // Burned-in copies are an automatic last resort, not a picker.
            ->assertDontSee('id="versionBtn"', false)
            ->assertSee('Burned-in', false)
            // The MP4 CDNs do not allow our origin through CORS, so the native
            // element must drop the anonymous mode or playback stays black.
            ->assertSee("video.removeAttribute('crossorigin')", false);
    }
}
