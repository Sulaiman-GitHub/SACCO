<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('payments:reconcile')->everyFifteenMinutes()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/payments-reconcile.log'));

Schedule::command('withdrawals:expire')->dailyAt('12:10')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/withdrawal-expiry.log'));

Schedule::command('loans:check-overdue')->dailyAt('12:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/overdue-scan.log'));
