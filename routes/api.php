<?php

use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

// Sync receivers — API key auth only (server-to-server, no user login involved)
Route::middleware('sync.api_key')->group(function () {
    Route::post('/sync/receive-employees', [SyncController::class, 'receiveEmployees']);
    Route::post('/sync/receive-offices', [SyncController::class, 'receiveOffices']);
    Route::post('/sync/receive-office-divisions', [SyncController::class, 'receiveOfficeDivisions']);
    Route::post('/sync/receive-work-schedules', [SyncController::class, 'receiveWorkSchedules']);
    Route::post('/sync/receive-attendances', [SyncController::class, 'receiveAttendances']);
});

// Dashboard data — public, no login, read-only
Route::get('/sync/counts', [SyncController::class, 'counts']);
Route::get('/sync/logs', [SyncController::class, 'logs']);
Route::get('/sync/pending-counts', [SyncController::class, 'pendingCounts']);

// Push triggers — called from this project's own dashboard button
Route::post('/sync/push-employees', [SyncController::class, 'pushEmployees']);
Route::post('/sync/push-offices', [SyncController::class, 'pushOffices']);
Route::post('/sync/push-office-divisions', [SyncController::class, 'pushOfficeDivisions']);
Route::post('/sync/push-work-schedules', [SyncController::class, 'pushWorkSchedules']);
Route::post('/sync/push-attendances', [SyncController::class, 'pushAttendances']);
