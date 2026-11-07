<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One user's participation in one challenge.
 *
 * The streak counters live here rather than being derived from `check_ins` on
 * every read: the bot renders a streak on nearly every message, and the ledger
 * of check-ins is the audit trail these are reconciled against.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('challenge_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('joined_at');

            /*
            | The period they came in on. Obligations begin here: a late joiner is
            | not penalised for periods that closed before they existed, so no
            | check-in row is ever created below this index.
            */
            $table->unsignedSmallInteger('joined_period_index');

            $table->string('status', 32);

            $table->unsignedSmallInteger('current_streak')->default(0);
            $table->unsignedSmallInteger('longest_streak')->default(0);

            $table->unsignedTinyInteger('freezes_total')->default(0);
            $table->unsignedTinyInteger('freezes_used')->default(0);

            /*
            | How many times this participant has missed a period with no freeze
            | left. Recorded but not acted on: the rule is that a reset costs the
            | streak and nothing else, because the slot they are occupying may
            | have been paid for with a friend's invite. A future "N resets →
            | auto-remove" policy can read this counter without a schema change.
            */
            $table->unsignedSmallInteger('streak_resets_count')->default(0);

            $table->timestamps();

            // One participation per user per challenge; also the join-flow guard.
            $table->unique(['challenge_id', 'user_id']);

            // "My challenges", the most-run query on the platform.
            $table->index(['user_id', 'status']);

            // Reminder and rollover fan-out: the active roster of one challenge.
            $table->index(['challenge_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('challenge_participants');
    }
};
