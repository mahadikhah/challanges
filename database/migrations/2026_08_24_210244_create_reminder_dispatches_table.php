<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One reminder owed to one participant for one period.
 *
 * The row is claimed *before* the message is sent, so the unique key below is
 * what makes the nightly sweep safe to re-run: a second pass collides instead of
 * sending a duplicate. Getting this wrong means messaging thousands of people
 * twice, which is the fastest way to have a bot reported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reminder_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_period_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 48);

            /*
            | When it is due, in UTC, computed from the challenge's timezone. Fan-out
            | is staggered off this with `delay()` to respect Telegram's per-chat
            | rate limit.
            |
            | `dateTime` for the same reason the period boundary it derives from is:
            | MySQL's TIMESTAMP stops at 2038-01-19 and a long timeline does not.
            */
            $table->dateTime('scheduled_for');

            /*
            | Null means claimed but not yet confirmed sent. A row that stays null
            | is a failed send, not a missing one.
            */
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['challenge_participant_id', 'challenge_period_id', 'kind'],
                'reminder_dispatches_participant_period_kind_unique',
            );

            // The dispatch queue: due, not yet sent.
            $table->index(['scheduled_for', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_dispatches');
    }
};
