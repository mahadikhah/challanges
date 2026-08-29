<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One participant's run through a timed-session challenge's steps, per period.
 *
 * A session is a *way of arriving* at a check-in, never evidence of one: only
 * `status = completed` means anything to the settlement engine, which is why an
 * unswept `in_progress` row can never accidentally count as a success.
 *
 * **One open session per (participant, period)** — a double-tapped "Start" must
 * converge, not duplicate. MySQL has no partial unique index, so the constraint
 * rides a stored generated column: `open` is 1 exactly while the session is
 * `in_progress` and NULL otherwise, and MySQL ignores NULLs in unique indexes.
 * Derived rather than maintained so it cannot drift out of step with `status`.
 * The unique index is the schema half; `StartCheckInSession` adds the row-level
 * lock the double-tap race actually needs.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('check_in_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_period_id')->constrained()->cascadeOnDelete();

            $table->string('status', 32);

            $table->timestamp('started_at');

            $table->timestamp('completed_at')->nullable();

            /*
            | The step the session is waiting on, NULL once it is finished.
            | The advancing action rejects any submission that names another
            | order, which is the defence against replayed callback data.
            */
            $table->unsignedInteger('current_step_order')->nullable();

            /*
            | 1 while the session is in_progress, else NULL — see the class
            | docblock. Stored (not virtual) because MySQL will only put a
            | generated column in a unique index if it is stored.
            */
            $table->tinyInteger('open')->nullable()
                ->storedAs("CASE WHEN status = 'in_progress' THEN 1 ELSE NULL END");

            $table->timestamps();

            $table->unique(
                ['challenge_participant_id', 'challenge_period_id', 'open'],
                'checkin_sessions_one_open',
            );

            // The stale-session sweep: everything open in a closed period.
            $table->index(['challenge_period_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_in_sessions');
    }
};
