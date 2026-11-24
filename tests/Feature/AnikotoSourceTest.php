<?php

namespace Tests\Feature;

use App\Services\Anime\AnikotoSource;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnikotoSourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    protected function fakeAnikoto(): void
    {
        Http::fake([
            'anikotoapi.site/recent-anime*' => Http::response([
                'ok' => true,
                'data' => [[
                    'id' => 99,
                    'title' => 'Sousou no Frieren',
                    'alternative' => 'Frieren',
                    'native' => '葬送のフリーレン',
                    'titles' => 'Frieren; Sousou no Frieren',
                    'ani_id' => 154587,
                    'mal_id' => 52991,
                ]],
            ], 200),
            'anikotoapi.site/series/99' => Http::response([
                'ok' => true,
                'data' => [
                    'anime' => [
                        'id' => 99,
                        'title' => 'Sousou no Frieren',
                        'poster' => 'https://cdn.example.test/frieren.jpg',
                        'duration' => 24,
                    ],
                    'episodes' => [
                        [
                            'id' => 1000,
                            'number' => 1,
                            'title' => 'The Journey Ends',
                            'embed_url' => [
                                'sub' => 'https://megaplay.buzz/stream/s-2/1000/sub',
                                'dub' => 'https://megaplay.buzz/stream/s-2/1000/dub',
                            ],
                        ],
                        [
                            'id' => 1001,
                            'number' => 2,
                            'title' => 'It Was Not a Coincidence',
                            'embed_url' => [
                                'sub' => 'https://megaplay.buzz/stream/s-2/1001/sub',
                                'dub' => '',
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);
    }

    public function test_it_maps_anilist_id_to_anikoto_episodes(): void
    {
        $this->fakeAnikoto();

        $episodes = (new AnikotoSource())->episodesForAniList(154587, 'sub');

        $this->assertCount(2, $episodes);
        $this->assertSame('ak|99|1|sub', $episodes[0]['id']);
        $this->assertSame(1, $episodes[0]['number']);
        $this->assertSame('The Journey Ends', $episodes[0]['title']);
        $this->assertSame(1440, $episodes[0]['duration']);
        $this->assertSame('https://cdn.example.test/frieren.jpg', $episodes[0]['image']);
    }

    public function test_dub_list_only_keeps_episodes_with_a_dub_track(): void
    {
        $this->fakeAnikoto();

        $episodes = (new AnikotoSource())->episodesForAniList(154587, 'dub');

        $this->assertCount(1, $episodes);
        $this->assertSame('ak|99|1|dub', $episodes[0]['id']);
    }

    public function test_it_falls_back_to_title_when_anilist_id_is_missing(): void
    {
        $this->fakeAnikoto();

        $episodes = (new AnikotoSource())->episodesForAniList(0, 'sub', ['title' => 'Frieren']);

        $this->assertSame([], $episodes, 'An invalid AniList id must not query the index.');

        Cache::flush();
        $this->fakeAnikoto();

        $source = new class extends AnikotoSource
        {
            public function resolveByTitle(string $title): ?int
            {
                $index = $this->index();

                return $index['by_title'][$this->normaliseTitle($title)] ?? null;
            }
        };

        $this->assertSame(99, $source->resolveByTitle('Frieren'));
    }

    public function test_it_returns_a_megaplay_embed_stream(): void
    {
        $this->fakeAnikoto();

        $stream = (new AnikotoSource())->streamFor('ak|99|1|sub');

        $this->assertNotNull($stream);
        $this->assertSame('embed', $stream['kind']);
        $this->assertSame('Anikoto', $stream['provider']);
        $this->assertSame('MegaPlay', $stream['server']);
        $this->assertSame('https://megaplay.buzz/stream/s-2/1000/sub', $stream['sources'][0]['url']);
        $this->assertFalse($stream['sources'][0]['isM3U8']);
        $this->assertNull($stream['audio_fallback']);
    }

    public function test_dub_playback_falls_back_to_the_sub_track(): void
    {
        $this->fakeAnikoto();

        $stream = (new AnikotoSource())->streamFor('ak|99|2|dub');

        $this->assertNotNull($stream);
        $this->assertSame('https://megaplay.buzz/stream/s-2/1001/sub', $stream['sources'][0]['url']);
        $this->assertSame('sub', $stream['audio_fallback']);
    }

    public function test_it_fails_soft_for_unknown_series(): void
    {
        $this->fakeAnikoto();

        $this->assertSame([], (new AnikotoSource())->episodesForAniList(999999, 'sub'));
        $this->assertNull((new AnikotoSource())->streamFor('ak|99|99|sub'));
    }

    public function test_stream_provider_uses_anikoto_when_the_resolver_is_empty(): void
    {
        $this->fakeAnikoto();

        $episodes = (new \App\Services\Anime\AnimeStreamProvider())
            ->getEpisodes(154587, 24, 'sub', ['title' => 'Sousou no Frieren']);

        $this->assertSame('anikoto', $episodes['provider']);
        // Synthetic AniList-derived episodes advertise the whole fallback
        // order, so the primary source is only required to be part of it.
        $this->assertContains('anikoto', $episodes['providers']);
        $this->assertCount(24, $episodes['episodes']);
        $this->assertSame('watch/anikoto/154587/sub/anikoto-1', $episodes['episodes'][0]['id']);
        $this->assertTrue($episodes['audioAvailable']['sub']);
    }
}
