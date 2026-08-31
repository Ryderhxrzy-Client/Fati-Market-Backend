<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Release item holds from checkouts that were never paid for.
 *
 * Runs often enough that an abandoned checkout frees its item promptly after
 * the reservation window lapses, and `withoutOverlapping` keeps two sweeps
 * from racing on the same rows.
 */
Schedule::command('checkouts:expire')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

/*
 * Clear out access tokens that lapsed more than a day ago. Sanctum already
 * refuses an expired token, so this only keeps `personal_access_tokens` from
 * growing without bound as week-old logins pile up.
 */
Schedule::command('sanctum:prune-expired --hours=24')
    ->daily();

/*
 * The 6h / 1h / 30m meet-up reminders for sellers bringing items in. Each
 * threshold fires once per schedule; a 15-minute cadence keeps even the
 * 30-minute reminder within a useful window.
 */
Schedule::command('meetups:remind')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
