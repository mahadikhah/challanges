<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A challenge: one shared, fixed timeline that every participant is measured
 * against. Late joiners catch up on it rather than getting a personal clock,
 * which is why the schedule lives here on the challenge and not per participant.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('challenges', function (Blueprint $table) {
            $table->id();

            /*
            | Cascades: a challenge has no meaning without its creator, and the
            | periods, participants and check-ins hanging off it cascade in turn.
            */
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            $table->string('period_type', 32);

            // Only meaningful for `period_type = custom`; N days per period.
            $table->unsignedSmallInteger('custom_period_days')->nullable();

            /*
            | Stored UTC like every other timestamp. Period boundaries are
            | computed in `timezone` and only then converted, so the challenge's
            | own zone has to travel with it — the server's is irrelevant and the
            | creator may not live in it.
            */
            $table->timestamp('starts_at');
            $table->unsignedSmallInteger('total_periods');
            $table->string('timezone', 64);

            $table->string('visibility', 32);
            $table->string('proof_type', 32);

            /*
            | Private by default: publishing someone's proof to the rest of the
            | challenge is a decision the creator has to make deliberately, and
            | only image proofs can honour it at all.
            */
            $table->boolean('proof_is_public')->default(false);

            // Freezes each participant starts with; copied onto them on join.
            $table->unsignedTinyInteger('default_freezes')->default(0);

            $table->string('status', 32);

            // Set when posted to the announcement channel; the double-post guard.
            $table->timestamp('announced_at')->nullable();

            $table->timestamps();

            /*
            | The channel feed ("what public challenges are open?") and the
            | rollover sweep ("which active challenges have periods closing?")
            | are the two hot queries, and both filter on status.
            */
            $table->index(['status', 'visibility']);
            $table->index(['status', 'starts_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('challenges');
    }
};
