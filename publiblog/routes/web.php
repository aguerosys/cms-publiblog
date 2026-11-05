<?php

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'superadmin'])->group(function () {
    Route::redirect('/', '/blogs');

    Route::resource('blogs', BlogController::class)->except(['destroy']);
    Route::post('blogs/{blog}/test-connection', [BlogController::class, 'testConnection'])->name('blogs.test-connection');
    Route::post('blogs/{blog}/confirm-fingerprint', [BlogController::class, 'confirmFingerprint'])->name('blogs.confirm-fingerprint');

    Route::prefix('blogs/{blog}')->scopeBindings()->group(function () {
        Route::get('articles', [ArticleController::class, 'index'])->name('articles.index');
        Route::post('articles', [ArticleController::class, 'store'])->name('articles.store');
        Route::get('articles/{article}/edit', [ArticleController::class, 'edit'])->name('articles.edit');
        Route::put('articles/{article}', [ArticleController::class, 'update'])->name('articles.update');
        Route::post('articles/{article}/retry', [ArticleController::class, 'retry'])->name('articles.retry');
        Route::delete('articles/{article}', [ArticleController::class, 'destroy'])->name('articles.destroy');

        Route::post('media', [MediaController::class, 'store'])->name('media.store');
        Route::get('media/{media}', [MediaController::class, 'show'])->name('media.show');
    });
});
