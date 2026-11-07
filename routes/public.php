<?php

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

// Detail Manga, async chapter list & reader
Route::get('/manga/{id}/chapters.json', [MangaController::class, 'chaptersJson'])->name('manga.chapters.json');
Route::get('/manga/{id}/chapter/{chapterId}/pages-json', [MangaController::class, 'chapterPagesJson'])->name('manga.chapter.pages-json');
Route::get('/manga/{id}/chapter/{chapterId}', [MangaController::class, 'read'])->name('manga.read');
Route::get('/manga/{id}', [MangaController::class, 'show'])->name('manga.show');

// Proxy Gambar untuk Bypass Blokir ISP / Sensor DNS
Route::get('/manga/proxy/image', [MangaController::class, 'proxyImage'])->name('manga.proxy.image');
