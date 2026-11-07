<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One invite code, claimable exactly once.
 *
 * `code` is unique and `invited_user_id` is unique, which together encode the
 * two rules that matter: a code cannot be redeemed twice, and a user can be
 * attributed to at most one inviter for their whole life. The second is the
 * structural half of "credit only a brand-new user" — even if the eligibility
 * check were bypassed, the database would still refuse to attribute the same
 * person twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inviter_id')->constrained('users')->cascadeOnDelete();
            $table->string('code', 32)->unique();

            /*
            | Null while unclaimed. `nullOnDelete` rather than cascade: if the
            | invited user is later deleted, the inviter still earned those coins
            | and the row still explains a ledger entry.
            */
            $table->foreignId('invited_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();

            $table->timestamp('credited_at')->nullable();
            $table->string('status', 32);
            $table->timestamps();

            $table->index(['inviter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invites');
    }
};
