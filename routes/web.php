<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - BiblioManga (authenticated area)
|--------------------------------------------------------------------------
|
| Public manga pages live in routes/public.php and are registered without
| the "web" middleware group so Vercel can cache them at the edge.
|
*/

// Optional User Profile & Auth routes (untuk kompatibilitas)
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
