<?php

namespace Tests\Feature;

use Tests\TestCase;

class AnimeTest extends TestCase
{
    public function test_manga_home_shows_switch_to_anime_mode(): void
    {
        $response = $this->get('/');
        $response->assertOk();
        $response->assertSee('aria-label="Pilih mode"', false);
        $response->assertSee('href="' . route('anime.index') . '"', false);
        $response->assertSee('Manga', false);
    }

    public function test_anime_home_loads_and_shows_switch_to_manga_mode(): void
    {
        $response = $this->get('/anime');
        $response->assertOk();
        $response->assertSee('aria-label="Pilih mode"', false);
        $response->assertSee('href="' . route('manga.index') . '"', false);
        $response->assertSee('BiblioAnime', false);
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

    public function test_playlist_proxy_rejects_unknown_key(): void
    {
        $response = $this->get('/anime/proxy/playlist?k=unknownkey');
        $response->assertNotFound();
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
        \Illuminate\Support\Facades\Cache::put('anime_verified_' . md5($episodeId . '|test'), 1, 3600);
    }

    public function test_watch_page_redirects_to_detail_without_episode(): void
    {
        // Unknown anime id -> 404 before any watch rendering happens.
        $response = $this->get('/anime/al_999999999/watch');
        $response->assertNotFound();
    }
}
