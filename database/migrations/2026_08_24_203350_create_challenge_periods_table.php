<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The materialised timeline of a challenge, one row per period.
 *
 * Materialised rather than recomputed on demand so that "which periods close in
 * the next hour?" and "which period is open right now?" are ordinary indexed
 * queries, and so reminder dispatch and rollover are idempotent against a fixed
 * set of rows instead of re-deriving dates every run.
 *
 * Boundaries are a **half-open interval**: `starts_at` is inclusive, `ends_at`
 * is exclusive, and period N's `ends_at` equals period N+1's `starts_at`. So the
 * open period is `starts_at <= now < ends_at`, with no one-second gap between
 * periods and no instant belonging to two of them.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('challenge_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();

            /*
            | Zero-based position on the timeline. `index` is a MySQL reserved
            | word, which is harmless through the query builder (it backtick-
            | quotes every identifier) but a trap in any hand-written SQL.
            */
            $table->unsignedSmallInteger('index');

            $table->timestamp('starts_at');
            $table->timestamp('ends_at');

            /*
            | Stamped once the period has been settled: every participant's
            | check-in resolved to approved, frozen or missed. This is what makes
            | rollover safe to re-run — a period that has already been closed is
            | skipped rather than penalising everyone a second time.
            */
            $table->timestamp('rolled_over_at')->nullable();

            $table->timestamps();

            // Makes materialisation idempotent: re-running cannot duplicate a period.
            $table->unique(['challenge_id', 'index']);

            /*
            | The rollover sweep asks for periods that have ended and are not yet
            | closed, across all challenges; the reminder sweep asks the mirror
            | question about periods about to end.
            */
            $table->index(['ends_at', 'rolled_over_at']);
            $table->index(['challenge_id', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('challenge_periods');
    }
};
