<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduler (Laravel Cloud: enable the scheduler on the app or worker
| cluster; locally: the `scheduler` compose service runs schedule:work).
*/

// Durable outbox reconciler: re-dispatches anything a lost queue message left behind.
Schedule::command('noise:reconcile')->everyMinute()->onOneServer()->withoutOverlapping(5);

// Hourly deep reconciliation of raw rows vs minute rollups.
Schedule::command('noise:reconcile --deep')->hourlyAt(7)->onOneServer()->withoutOverlapping(30);

// Daily retention (staged, retryable deletion honoring keep flags).
Schedule::command('noise:retention')
    ->dailyAt('03:30')
    ->onOneServer()
    ->withoutOverlapping(120)
    ->onSuccess(fn () => Cache::forever('noise:retention:last_run', ['at' => now()->toIso8601String(), 'ok' => true]))
    ->onFailure(fn () => Cache::forever('noise:retention:last_run', ['at' => now()->toIso8601String(), 'ok' => false]));

// Prune Laravel's own failed-job and batch tables.
Schedule::command('queue:prune-failed --hours=720')->daily()->onOneServer();
