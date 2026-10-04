<?php

use App\Http\Controllers\SidecarController;
use Illuminate\Support\Facades\Route;

Route::middleware('sidecar')->prefix('sidecar')->group(function () {
    Route::get('/work', [SidecarController::class, 'work'])->name('sidecar.work');
    Route::post('/runs/{run}/snapshots', [SidecarController::class, 'snapshots'])->name('sidecar.snapshots');
    Route::post('/runs/{run}/finish', [SidecarController::class, 'finish'])->name('sidecar.finish');
});
