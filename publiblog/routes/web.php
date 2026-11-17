<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');

    Route::get('/two-factor', [TwoFactorController::class, 'show'])->name('two-factor.show');
    Route::post('/two-factor', [TwoFactorController::class, 'verify'])->name('two-factor.verify');
    Route::post('/two-factor/resend', [TwoFactorController::class, 'resend'])->name('two-factor.resend');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::redirect('/', '/blogs');
    Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');

    // Solo superadmin: alta y conexión de blogs, y gestión de usuarios. Va antes que blogs/{blog}.
    Route::middleware('superadmin')->group(function () {
        Route::resource('blogs', BlogController::class)->only(['create', 'store', 'edit', 'update']);
        Route::post('blogs/{blog}/test-connection', [BlogController::class, 'testConnection'])->name('blogs.test-connection');
        Route::post('blogs/{blog}/regenerate', [BlogController::class, 'regenerate'])->name('blogs.regenerate');
        Route::post('blogs/{blog}/verify', [BlogController::class, 'verify'])->name('blogs.verify');
        Route::post('blogs/{blog}/ssi/enable', [BlogController::class, 'enableSsi'])->name('blogs.ssi.enable');
        Route::post('blogs/{blog}/ssi/disable', [BlogController::class, 'disableSsi'])->name('blogs.ssi.disable');
        Route::post('blogs/{blog}/confirm-fingerprint', [BlogController::class, 'confirmFingerprint'])->name('blogs.confirm-fingerprint');

        Route::resource('users', UserController::class)->except(['show']);
    });

    // Cada usuario ve solo sus blogs: el filtro global y las políticas hacen el resto.
    Route::resource('blogs', BlogController::class)->only(['index', 'show']);

    Route::prefix('blogs/{blog}')->scopeBindings()->group(function () {
        Route::get('articles', [ArticleController::class, 'index'])->name('articles.index');
        Route::post('articles', [ArticleController::class, 'store'])->name('articles.store');
        Route::get('articles/{article}/edit', [ArticleController::class, 'edit'])->name('articles.edit');
        Route::put('articles/{article}', [ArticleController::class, 'update'])->name('articles.update');
        Route::post('articles/{article}/retry', [ArticleController::class, 'retry'])->name('articles.retry');
        Route::delete('articles/{article}', [ArticleController::class, 'destroy'])->name('articles.destroy');

        Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('categories/create', [CategoryController::class, 'create'])->name('categories.create');
        Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::get('categories/{category}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
        Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        Route::post('media', [MediaController::class, 'store'])->name('media.store');
        Route::get('media/{media}', [MediaController::class, 'show'])->name('media.show');
    });
});
