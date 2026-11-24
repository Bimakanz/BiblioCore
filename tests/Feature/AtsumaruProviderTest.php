<?php

namespace Tests\Feature;

use App\Services\Manga\MangaService;
use App\Services\Manga\Providers\AtsumaruProvider;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class AtsumaruProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /**
     * Provider with the network layer replaced by canned JSON. Atsumaru sits
     * behind Cloudflare's TLS fingerprinting, so the real transport is curl
     * and cannot be mocked through Http::fake().
     *
     * @param  array<string, array<string, mixed>>  $fixtures
     */
    protected function provider(array $fixtures): AtsumaruProvider
    {
        return new class($fixtures) extends AtsumaruProvider
        {
            /** @param array<string, array<string, mixed>> $fixtures */
            public function __construct(private array $fixtures)
            {
                parent::__construct();
            }

            protected function getJson(string $path, array $query = [], int $timeout = 6): ?array
            {
                foreach ($this->fixtures as $needle => $payload) {
                    if (str_starts_with($path, $needle)) {
                        return $payload;
                    }
                }

                return null;
            }
        };
    }

    protected function fixtures(): array
    {
        return [
            '/api/search/manga' => [
                'hits' => [[
                    'document' => [
                        'id' => 'm1',
                        'title' => 'Frieren: Beyond Journey\'s End',
                        'otherNames' => ['Frieren', 'Sousou no Frieren'],
                        'chapterCount' => 2,
                    ],
                ]],
            ],
            '/api/manga/allChapters' => [
                'chapters' => [
                    [
                        'id' => 'c1',
                        'title' => 'Chapter 12',
                        'number' => 12,
                        'index' => 11,
                        'pageCount' => 2,
                        'createdAt' => 1752221536064,
                    ],
                    [
                        'id' => 'c2',
                        'title' => 'Chapter 13',
                        'number' => 13,
                        'index' => 12,
                        'pageCount' => 1,
                        'createdAt' => 1752307936064,
                    ],
                ],
            ],
            '/api/read/chapter' => [
                'readChapter' => [
                    'pages' => [
                        ['image' => '/static/pages/m1/c1/1.webp', 'number' => 1],
                        ['image' => '/static/pages/m1/c1/0.webp', 'number' => 0],
                    ],
                ],
            ],
        ];
    }

    public function test_it_returns_english_chapters_for_a_title(): void
    {
        $chapters = $this->provider($this->fixtures())->getChapters('Frieren', ['Sousou no Frieren']);

        $this->assertCount(2, $chapters);
        $this->assertSame('atsu_c1', $chapters[0]['id']);
        $this->assertSame('12', $chapters[0]['chapter']);
        $this->assertSame('en', $chapters[0]['language']);
        $this->assertSame('Atsumaru', $chapters[0]['provider']);
        $this->assertSame('Chapter 12', $chapters[0]['title']);
    }

    public function test_it_returns_unmatched_titles_as_an_empty_list(): void
    {
        $provider = $this->provider([
            '/api/search/manga' => [
                'hits' => [['document' => [
                    'id' => 'other',
                    'title' => 'Completely Different Series',
                    'otherNames' => [],
                ]]],
            ],
        ]);

        $this->assertSame([], $provider->getChapters('Frieren'));
    }

    public function test_it_resolves_cdn_pages_in_reading_order(): void
    {
        $provider = $this->provider($this->fixtures());
        $provider->getChapters('Frieren');

        $pages = $provider->getChapterPages('atsu_c1');

        $this->assertNotNull($pages);
        $this->assertSame(2, $pages['count']);
        $this->assertSame('https://cdn.atsu.moe/static/pages/m1/c1/0.webp', $pages['pages'][0]['directUrl']);
        $this->assertSame('https://cdn.atsu.moe/static/pages/m1/c1/1.webp', $pages['pages'][1]['directUrl']);
        $this->assertStringStartsWith('https://wsrv.nl/?url=', $pages['pages'][0]['proxyUrl']);
    }

    public function test_manga_service_routes_the_atsu_prefix_to_the_provider(): void
    {
        $atsumaru = Mockery::mock(AtsumaruProvider::class);
        $atsumaru->shouldReceive('getChapterPages')
            ->once()
            ->with('atsu_c1')
            ->andReturn(['chapterId' => 'atsu_c1', 'pages' => []]);

        $service = new MangaService(null, null, null, null, null, null, null, null, $atsumaru);

        $this->assertSame('atsu_c1', $service->getChapterPages('atsu_c1')['chapterId']);
    }
}
