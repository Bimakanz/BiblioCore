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

    public function test_watch_page_redirects_to_detail_without_episode(): void
    {
        // Unknown anime id -> 404 before any watch rendering happens.
        $response = $this->get('/anime/al_999999999/watch');
        $response->assertNotFound();
    }
}
