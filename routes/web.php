<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PublicDashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

Route::middleware('throttle:20,1')->group(function (): void {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});

// Public, read-only dashboard for a device an owner shared. The token is the only credential.
Route::get('/share/{token}', [PublicDashboardController::class, 'show'])
    ->middleware('throttle:public-share')
    ->where('token', '[A-Za-z0-9_]{1,128}')
    ->name('share.show');

// Liveness is Laravel's /up. Readiness checks dependencies.
Route::get('/health/ready', HealthController::class)->name('health.ready');
