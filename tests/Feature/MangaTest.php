<?php

use App\Services\Manga\MangaService;
use App\Services\Manga\Providers\AniListProvider;
use App\Services\Manga\Providers\KomikIndoProvider;
use App\Services\Manga\Providers\KomikuProvider;
use App\Services\Manga\Providers\MangaDexProvider;
use App\Services\Manga\Providers\MangaNatoProvider;
use App\Services\Manga\Providers\MangaPillProvider;
use App\Services\Manga\Providers\MultiSourceProvider;

function fakeMangaDetail(): array
{
    return [
        'id' => 'a77742b1-befd-49a4-bff5-1ad4e6b0ef7b',
        'title' => 'Chainsaw Man',
        'format' => 'MANGA',
        'altTitles' => ['Chainsaw Man'],
        'description' => 'Denji memiliki mimpi sederhana: hidup bahagia bersama gadis yang disukainya.',
        'coverUrl' => 'https://example.com/chainsaw-man.jpg',
        'status' => 'Ongoing',
        'year' => 2018,
        'contentRating' => 'Safe',
        'tags' => ['Action', 'Comedy', 'Supernatural'],
        'authors' => 'Tatsuki Fujimoto',
        'artists' => 'Tatsuki Fujimoto',
    ];
}

function fakeChapter(string $id, string $number, string $language): array
{
    return [
        'id' => $id,
        'chapter' => $number,
        'title' => '',
        'language' => $language,
        'provider' => 'MangaDex',
        'publishAt' => '2024-01-01',
        'readPath' => '',
    ];
}

test('manga homepage can be rendered', function () {
    $this->mock(MangaService::class, function ($mock) {
        $mock->shouldReceive('getPopular')->once()->andReturn([
            'data' => [[
                'id' => 'a77742b1-befd-49a4-bff5-1ad4e6b0ef7b',
                'title' => 'Chainsaw Man',
                'coverUrl' => 'https://example.com/chainsaw-man.jpg',
                'status' => 'Ongoing',
                'format' => 'MANGA',
                'tags' => ['Action', 'Comedy'],
            ]],
            'total' => 1,
            'totalPages' => 1,
        ]);
        $mock->shouldReceive('getGenres')->once()->andReturn(['Action', 'Comedy']);
    });

    $response = $this->get('/');

    $response->assertStatus(200);
    $response->assertSee('BiblioManga');
    $response->assertSee('Chainsaw Man');
});

test('manga detail page can be rendered for a valid manga id', function () {
    $this->mock(MangaService::class, function ($mock) {
        $mock->shouldReceive('getDetail')->once()->andReturn(fakeMangaDetail());
        $mock->shouldReceive('getChapters')->once()->andReturn([
            fakeChapter('chapter-2', '2', 'en'),
            fakeChapter('chapter-1', '1', 'en'),
        ]);
    });

    $response = $this->get('/manga/a77742b1-befd-49a4-bff5-1ad4e6b0ef7b');

    $response->assertStatus(200);
    $response->assertSee('Chainsaw Man');
    $response->assertSee('Daftar Chapter');
});

test('image proxy rejects local and non-http urls', function () {
    $this->get('/manga/proxy/image?url='.urlencode('http://127.0.0.1/secret'))->assertNotFound();
    $this->get('/manga/proxy/image?url='.urlencode('http://localhost/secret'))->assertNotFound();
    $this->get('/manga/proxy/image?url='.urlencode('file:///etc/passwd'))->assertNotFound();
});

test('multi provider aggregator merges, deduplicates and sorts chapters', function () {
    $aniList = Mockery::mock(AniListProvider::class);
    $mangaDex = Mockery::mock(MangaDexProvider::class);
    $komiku = Mockery::mock(KomikuProvider::class);
    $komikIndo = Mockery::mock(KomikIndoProvider::class);
    $mangaPill = Mockery::mock(MangaPillProvider::class);
    $mangaNato = Mockery::mock(MangaNatoProvider::class);
    $multiSource = Mockery::mock(MultiSourceProvider::class);

    $mangaDex->shouldReceive('getDetail')->once()->andReturn(fakeMangaDetail());
    $mangaDex->shouldReceive('getChapters')->once()->andReturn([
        fakeChapter('dex-3', '3', 'en'),
        fakeChapter('dex-2', '2', 'en'),
        fakeChapter('dex-2-duplicate', '2', 'en'),
    ]);

    $komiku->shouldReceive('getChapters')->once()->andReturn([
        fakeChapter('kmk_1', '1', 'id'),
    ]);
    $komikIndo->shouldReceive('getChapters')->once()->andReturn([]);
    $multiSource->shouldReceive('getIndoChapters')->once()->andReturn([]);

    $mangaPill->shouldReceive('getChapters')->once()->andReturn([
        fakeChapter('mp_4', '4', 'en'),
    ]);
    $multiSource->shouldReceive('getEnglishChapters')->once()->andReturn([]);
    $mangaNato->shouldReceive('getChapters')->once()->andReturn([]);

    $service = new MangaService($aniList, $mangaDex, $komiku, $komikIndo, $mangaPill, $mangaNato, $multiSource);

    $chapters = $service->getChapters('test-manga');

    // Duplicate EN chapter 2 collapses into one, leaving 3 EN chapters plus 1 ID chapter.
    expect($chapters)->toHaveCount(4)
        ->and(array_column($chapters, 'chapter'))->toBe(['4', '3', '2', '1'])
        ->and($chapters[0]['id'])->toBe('mp_4');
});
