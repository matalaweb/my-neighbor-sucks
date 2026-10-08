<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\InvitationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

Route::middleware('throttle:20,1')->group(function (): void {
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});

// Liveness is Laravel's /up. Readiness checks dependencies.
Route::get('/health/ready', HealthController::class)->name('health.ready');
