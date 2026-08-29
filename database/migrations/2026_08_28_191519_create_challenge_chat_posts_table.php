<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What has already been posted into a linked chat, so a re-dispatched job is a
 * no-op rather than a duplicate.
 *
 * The two broadcast kinds carry different natural keys, and one table holds
 * both rather than two tables with one shape each: a check-in announcement is
 * about one participant's one period (`period_id` + `participant_id`), while a
 * daily leaderboard is about one chat's one day (`post_date`). The half of the
 * key the kind does not use is left null, and each kind's uniqueness is
 * enforced by its own index over exactly the columns it uses — so the
 * database, not the job, is what guarantees "once per (chat, period,
 * participant)" and "once per (chat, date)".
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('challenge_chat_posts', function (Blueprint $table) {
            $table->id();

            /*
            | Cascades: post history for a chat nobody can post to any more has
            | nothing to guard, and a vanished row is the strongest form of
            | "will never send" there is.
            */
            $table->foreignId('challenge_chat_id')->constrained()->cascadeOnDelete();

            $table->string('post_kind', 32);

            /*
            | The check-in half of the key. Both nullable: a leaderboard post
            | is about neither a period nor a participant, and the unique
            | indexes below ignore rows where their columns are null.
            */
            $table->foreignId('challenge_period_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('challenge_participant_id')->nullable()->constrained()->cascadeOnDelete();

            // The leaderboard half of the key: the day the board describes.
            $table->date('post_date')->nullable();

            $table->timestamps();

            /*
            | Once per (chat, period, participant) — the check-in idempotency.
            | Named explicitly: the default Laravel name would run past MySQL's
            | 64-character identifier limit.
            */
            $table->unique(
                ['challenge_chat_id', 'post_kind', 'challenge_period_id', 'challenge_participant_id'],
                'chatposts_checkin_once',
            );

            // Once per (chat, date) — the daily leaderboard idempotency.
            $table->unique(['challenge_chat_id', 'post_kind', 'post_date'], 'chatposts_daily_once');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('challenge_chat_posts');
    }
};
