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
