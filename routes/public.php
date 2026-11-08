<?php

use App\Http\Controllers\AnimeController;
use App\Http\Controllers\MangaController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes (no session, edge-cache friendly)
|--------------------------------------------------------------------------
|
| These routes are intentionally registered WITHOUT the "web" middleware
| group. Manga pages are fully anonymous (favourites/history live in
| localStorage), so skipping session + CSRF lets Vercel cache the HTML at
| the edge and serve it instantly from the nearest POP.
|
*/

Route::get('/', [MangaController::class, 'index'])->name('manga.index');
Route::get('/landing', [MangaController::class, 'index'])->name('landing');

// API Live Search Debounce
Route::get('/manga/api/live-search', [MangaController::class, 'liveSearch'])->name('manga.api.live-search');

// Cache warm-up endpoint (optional token protected, see WARM_TOKEN env)
Route::get('/warm', [MangaController::class, 'warm'])->name('manga.warm');
Route::get('/warm/diag', [MangaController::class, 'diag'])->name('manga.warm.diag');

// Detail Manga, async chapter list & reader
Route::get('/manga/{id}/chapters.json', [MangaController::class, 'chaptersJson'])->name('manga.chapters.json');
Route::get('/manga/{id}/chapter/{chapterId}/pages-json', [MangaController::class, 'chapterPagesJson'])->name('manga.chapter.pages-json');
Route::get('/manga/{id}/chapter/{chapterId}', [MangaController::class, 'read'])->name('manga.read');
Route::get('/manga/{id}', [MangaController::class, 'show'])->name('manga.show');

// Anime mode (AniList catalogue + own HLS player, no embeds)
Route::get('/anime', [AnimeController::class, 'index'])->name('anime.index');
Route::get('/anime/api/live-search', [AnimeController::class, 'liveSearch'])->name('anime.api.live-search');
Route::get('/anime/proxy/playlist', [AnimeController::class, 'playlistProxy'])->name('anime.proxy.playlist');
Route::get('/anime/proxy/segment', [AnimeController::class, 'segmentProxy'])->name('anime.proxy.segment');
Route::get('/anime/proxy/subtitle', [AnimeController::class, 'subtitleProxy'])->name('anime.proxy.subtitle');
Route::get('/anime/subtitle/id.vtt', [AnimeController::class, 'translatedSubtitle'])->name('anime.subtitle.id');
Route::get('/anime/{id}/episodes.json', [AnimeController::class, 'episodesJson'])->name('anime.episodes.json');
Route::get('/anime/{id}/recommendations.json', [AnimeController::class, 'recommendationsJson'])->name('anime.recommendations.json');
Route::get('/anime/{id}/stream.json', [AnimeController::class, 'streamJson'])->name('anime.stream.json');
Route::get('/anime/{id}/watch', [AnimeController::class, 'watch'])->name('anime.watch');
Route::get('/anime/{id}', [AnimeController::class, 'show'])->name('anime.show');

// Proxy Gambar untuk Bypass Blokir ISP / Sensor DNS
Route::get('/manga/proxy/image', [MangaController::class, 'proxyImage'])->name('manga.proxy.image');
