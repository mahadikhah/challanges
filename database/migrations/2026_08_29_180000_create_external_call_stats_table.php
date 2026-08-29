<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Phase 13 Task 4: the external-call counters behind the System Health page.
|
| One row per (provider, day, outcome) — a day-grained counter, deliberately
| not a log: Telescope already holds the detail, but Telescope is pruned and
| can be disabled, and "how often has Bale been refusing this week?" must not
| depend on either. `count` is an incrementing integer rather than a row per
| call because a burst of sends is one number on a dashboard and a thousand
| rows nowhere.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_call_stats', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->date('day');
            $table->string('outcome');
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['provider', 'day', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_call_stats');
    }
};
