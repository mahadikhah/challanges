<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A slot a user holds: one permission to create, or to join, one challenge.
 *
 * Modelled as rows rather than counters so each slot carries its own provenance
 * and can name the challenge it was spent on. "Why can't I join anything?" is
 * then answerable from this table alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('source', 32);

            /*
            | Consumption is a timestamp, not a delete: a spent slot is history
            | worth keeping, and it is what links the spend to a challenge.
            */
            $table->timestamp('consumed_at')->nullable();

            /*
            | Nullable both because an unspent slot has no challenge, and because
            | a deleted challenge must not take the record of the spend with it.
            */
            $table->foreignId('challenge_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            // "Does this user have an unspent slot of this type?" — the hot query.
            $table->index(['user_id', 'type', 'consumed_at']);
            $table->index(['challenge_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlements');
    }
};
