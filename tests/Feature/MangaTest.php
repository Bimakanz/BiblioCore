<?php

test('manga homepage can be rendered', function () {
    $response = $this->get('/');
    $response->assertStatus(200);
    $response->assertSee('BiblioManga');
});

test('manga detail page can be rendered for valid manga id', function () {
    // Chainsaw Man ID
    $response = $this->get('/manga/a77742b1-befd-49a4-bff5-1ad4e6b0ef7b');
    $response->assertStatus(200);
    $response->assertSee('Chainsaw Man');
    $response->assertSee('Daftar Chapter');
});

test('multi provider aggregator returns combined chapters', function () {
    $service = app(App\Services\Manga\MangaService::class);
    $chapters = $service->getChapters('a96676e5-8ae2-425e-b549-7f15dd34a6d8'); // Komi-san
    expect(count($chapters))->toBeGreaterThan(50);
});

