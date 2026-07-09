<?php

use App\Http\Controllers\Api\V1\Sync\SyncConflictController;
use App\Http\Controllers\Api\V1\Sync\SyncPullController;
use App\Http\Controllers\Api\V1\Sync\SyncPushController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->group(function () {
    Route::get('sync', SyncPullController::class);
    Route::post('sync/push', SyncPushController::class);

    Route::get('sync/conflicts', [SyncConflictController::class, 'index']);
    Route::get('sync/conflicts/{syncConflict}', [SyncConflictController::class, 'show']);
    Route::post('sync/conflicts/{syncConflict}/resolve', [SyncConflictController::class, 'resolve']);
});
