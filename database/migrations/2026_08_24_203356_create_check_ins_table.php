<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One participant's obligation for one period, and how it turned out.
 *
 * This is the audit trail the streak counters on `challenge_participants` are
 * reconciled against, so rows are updated in place rather than replaced: there
 * is exactly one row per participant per period, and the unique index below is
 * what makes a double-tap, a retried webhook and a re-run rollover all safe.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_period_id')->constrained()->cascadeOnDelete();

            $table->string('status', 32);

            /*
            | For `proof_type = text_autogen`: the phrase this participant must
            | type for this period. Generated per participant per period, so it
            | cannot be pasted to someone else and still work. Stored because the
            | comparison has to be against what was actually issued.
            */
            $table->string('expected_phrase')->nullable();

            // Free text as typed, kept even on a mismatch so disputes are answerable.
            $table->text('submitted_text')->nullable();

            // Storage path for `proof_type = image_approval`, never a public URL.
            $table->string('proof_path')->nullable();

            $table->timestamp('submitted_at')->nullable();

            /*
            | Who approved or rejected an image proof. Nulled rather than cascaded
            | on delete: removing a reviewer must not erase the check-in they
            | reviewed.
            */
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            $table->timestamps();

            // One obligation per participant per period. The idempotency guarantee.
            $table->unique(['challenge_participant_id', 'challenge_period_id']);

            // Rollover: everything in a closing period that is not settled yet.
            $table->index(['challenge_period_id', 'status']);

            /*
            | The review queue. A low-cardinality column, but the value being
            | asked for (`submitted`) is always a small fraction of the table, so
            | the index earns its keep.
            */
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_ins');
    }
};
