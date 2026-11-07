<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a participant handed in at one step of one session.
 *
 * The media column mirrors `check_ins.proof_path` exactly — same name, same
 * nullable-string-of-storage-path shape, same `local` disk at the other end —
 * because that is already the platform's one proof-storage convention, and a
 * second one would split the admin review surface down the middle. It is NULL
 * for `button` steps, which carry no media at all.
 *
 * No unique index: a step is satisfied once (the advancing action moves
 * `current_step_order` on before another submission could be recorded), but
 * the rows are an audit trail of what arrived — including the too-long voice
 * message's path if one is ever kept — not an idempotency key.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('check_in_step_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('check_in_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_step_id')->constrained()->cascadeOnDelete();

            $table->timestamp('submitted_at');

            // Storage path for image/voice steps, never a public URL.
            $table->string('proof_path')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_in_step_submissions');
    }
};
