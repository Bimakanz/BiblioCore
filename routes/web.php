<?php

use App\Http\Controllers\MangaController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - BiblioManga (Open Manga Reader)
|--------------------------------------------------------------------------
|
| Akses publik tanpa batas untuk membaca manga dalam Bahasa Indonesia
| dan Bahasa Inggris tanpa perlu registrasi, login, ataupun admin.
|
*/

// Beranda & Katalog Manga Publik
Route::get('/', [MangaController::class, 'index'])->name('manga.index');
Route::get('/landing', [MangaController::class, 'index'])->name('landing');

// API Live Search Debounce
Route::get('/manga/api/live-search', [MangaController::class, 'liveSearch'])->name('manga.api.live-search');

// Detail Manga & Daftar Chapter
Route::get('/manga/{id}', [MangaController::class, 'show'])->name('manga.show');

// Pembaca Manga (Manga Reader)
Route::get('/manga/{id}/chapter/{chapterId}', [MangaController::class, 'read'])->name('manga.read');

// Proxy Gambar untuk Bypass Blokir ISP / Sensor DNS
Route::get('/manga/proxy/image', [MangaController::class, 'proxyImage'])->name('manga.proxy.image');

// Optional User Profile & Auth routes (untuk kompatibilitas)
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
