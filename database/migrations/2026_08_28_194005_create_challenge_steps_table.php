<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A timed-session challenge's step list, as the creator designed it.
 *
 * The design is frozen at creation (no editing once participants exist), so a
 * step row is immutable in practice — which is what makes
 * `CheckInStepSubmission.challenge_step_id` a stable reference to *what was
 * asked*, not to whatever the challenge's steps look like today.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('challenge_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();

            /*
            | Position in the sequence, 1-based. The session machinery keys on
            | it (`CheckInSession.current_step_order`), and the unique index
            | below keeps the sequence gapless-by-construction at the source:
            | a step list is validated ordered before it is ever inserted.
            */
            $table->unsignedInteger('step_order');

            $table->string('input_type', 16);

            /*
            | The gate: how long after the previous step (or the session's
            | start, for step 1) this step may be satisfied. Unsigned because
            | a negative wait is a nonsense a creator could only reach by
            | mistake, and the design validator sums these to bound the whole
            | session — a bound that only means anything if each term is ≥ 0.
            */
            $table->unsignedInteger('min_wait_seconds');

            // Cap on the voice message's duration; required iff input_type = voice.
            $table->unsignedInteger('voice_max_seconds')->nullable();

            /*
            | The step's instructions, shown to every participant. Creator
            | text, stored verbatim like a challenge title — the system strings
            | around it go through i18n, the creator's own words do not.
            */
            $table->string('label')->nullable();

            $table->timestamps();

            $table->unique(['challenge_id', 'step_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('challenge_steps');
    }
};
