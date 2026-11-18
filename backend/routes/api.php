<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EggController;
use App\Http\Controllers\IncubatorController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/egg/capture', [EggController::class, 'request']);
    Route::get('/egg/status', [EggController::class, 'status']);
    Route::get('/egg/history', [EggController::class, 'history']);
    Route::get('/egg/job/{job}', [EggController::class, 'show']);
    Route::get('/candling/settings', [EggController::class, 'settings']);
    Route::post('/candling/settings', [EggController::class, 'updateSettings']);
    Route::get('/incubator/latest', [IncubatorController::class, 'latest']);
    Route::get('/incubator/history', [IncubatorController::class, 'history']);
    Route::get('/incubator/photo/{filename}', [IncubatorController::class, 'photo'])->where('filename', '[A-Za-z0-9._-]+');
    Route::match(['get', 'post'], '/incubator/control', [IncubatorController::class, 'control']);
});

Route::middleware(['device.key', 'throttle:120,1'])->prefix('device')->group(function () {
    Route::get('/poll', [DeviceController::class, 'poll']);
    Route::post('/upload', [DeviceController::class, 'upload']);
});

Route::prefix('incubator')->group(function () {
    Route::post('/upload', [IncubatorController::class, 'upload'])->middleware(['device.key', 'throttle:120,1']);
    Route::post('/telemetry', [IncubatorController::class, 'telemetry'])->middleware(['device.key', 'throttle:120,1']);
    Route::get('/device-control', [IncubatorController::class, 'deviceControl'])->middleware(['device.key', 'throttle:120,1']);
});
