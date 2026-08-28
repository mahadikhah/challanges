<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| The platform's clockwork, one cron line each.
|
| On the shared host these are driven by system cron (`* * * * * php artisan
| schedule:run`), with a `queue:work --stop-when-empty --max-time=55` beside
| them — the database queue has no daemon and no Redis to lean on. Every command
| here is idempotent and cheap when idle, so a minute's overlap or a missed tick
| costs nothing but a little delay.
*/

// Close what has ended: activate started challenges, settle elapsed periods,
// complete finished timelines.
Schedule::command('challenges:roll-over')->everyMinute();

// Mint the coming minute's/day's reminder rows and queue the due ones,
// staggered one send a second.
Schedule::command('challenges:reminders')->everyMinute();
