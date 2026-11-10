<?php

use App\Http\Controllers\EggController;
use App\Http\Controllers\IncubatorController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [EggController::class, 'dashboard'])->name('dashboard');
Route::prefix('egg')->group(function () {
    Route::post('/capture', [EggController::class, 'request'])->name('egg.capture');
    Route::post('/upload', [EggController::class, 'upload'])->name('egg.upload');
    Route::get('/job/{job}', [EggController::class, 'show'])->name('egg.job');
    Route::get('/history', [EggController::class, 'history'])->name('egg.history');
    Route::get('/status', [EggController::class, 'status'])->name('egg.status');
});

Route::prefix('incubator')->group(function () {
    Route::post('/upload', [IncubatorController::class, 'upload'])->middleware(['device.key']);
    Route::get('/latest', [IncubatorController::class, 'latest']);
    Route::get('/history', [IncubatorController::class, 'history']);
    Route::get('/photo/{filename}', [IncubatorController::class, 'photo'])->where('filename', '[A-Za-z0-9._-]+');
    Route::match(['get', 'post'], '/control', [IncubatorController::class, 'control']);
});
