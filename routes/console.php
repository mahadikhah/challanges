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

// Post the daily leaderboard into each opted-in linked chat, in the
// challenge's own timezone's leaderboard hour, staggered like reminders.
Schedule::command('challenges:leaderboards')->everyMinute();

// Mini App bearer tokens are short-lived; this keeps `personal_access_tokens`
// from growing one app-open at a time. Retains tokens expired less than 24h,
// so debugging yesterday's session is still possible.
Schedule::command('sanctum:prune-expired')->daily();

// Close out invoice links nobody paid, so the payments audit stays a list of
// purchases rather than a landfill of abandoned carts.
Schedule::command('payments:sweep-abandoned')->daily();

// Enforce the proof-media retention window: delete the media of decided
// submissions past it, keeping the decision records forever. Voice/video
// proof is the platform's heaviest stored bytes, and shared hosting has a
// disk quota.
Schedule::command('challenges:prune-proof-media')->daily();

// Keep Telescope's database rows inside their retention window (default 72h,
// a Setting, so an admin can widen it during an investigation). The command
// reads the Setting itself, at prune time — see its docblock for why it must
// not be resolved here.
Schedule::command('observability:prune-telescope')->daily();

// Cron's own pulse: stamp the heartbeat row every minute. Its success is the
// evidence Tasks 4 and 5 read, because only cron can run it — and the optional
// external ping beside the stamp is the one thing that can detect cron dying
// outright, since a dead cron silences every schedule entry including this one.
Schedule::command('observability:heartbeat')->everyMinute();

// Task 5's stale-heartbeat check: reads the stamp above and alerts the ops
// chat when it has gone past the staleness bar. Scheduled after the
// heartbeat on purpose — the stamp this minute should exist before anyone
// judges its age — and subject to the same bound stated there: a totally
// dead cron silences this entry too.
Schedule::command('observability:alert-stale-heartbeat')->everyMinute();
