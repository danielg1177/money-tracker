<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily Plaid sync is off until bank accounts are finalized. Re-enable with:
// Schedule::command('plaid:daily-sync')->dailyAt('02:00');
// (requires `use Illuminate\Support\Facades\Schedule` and a cron/`schedule:run` worker.)
