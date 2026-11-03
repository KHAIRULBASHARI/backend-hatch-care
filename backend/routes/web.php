<?php

use App\Http\Controllers\EggController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', [EggController::class, 'dashboard'])->name('dashboard');
Route::prefix('egg')->group(function () {
    Route::post('/capture', [EggController::class, 'request'])->name('egg.capture');
    Route::get('/job/{job}', [EggController::class, 'show'])->name('egg.job');
    Route::get('/history', [EggController::class, 'history'])->name('egg.history');
    Route::get('/status', [EggController::class, 'status'])->name('egg.status');
});
