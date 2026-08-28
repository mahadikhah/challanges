<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A creator-owned channel or group registered as a challenge's "home" chat.
 *
 * The row is only ever written after two independent `getChatMember` checks —
 * the bot is admin and the creator is admin — because a chat the bot can post
 * to but nobody vouched for would let any participant bind an announcement
 * feed onto a chat they do not control. Verification is a moment in time, not
 * a permanent grant: both stamps are re-checked lazily before posts
 * (§2.6), and a chat that fails goes inactive rather than being deleted, so
 * the creator can see what they lost and re-add the bot.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('challenge_chats', function (Blueprint $table) {
            $table->id();

            /*
            | Cascades: a chat registered to a challenge has no meaning once the
            | challenge is gone.
            */
            $table->foreignId('challenge_id')->constrained()->cascadeOnDelete();

            /*
            | The Telegram-side id of the linked channel/group. Discovered only
            | from a forwarded message's `forward_from_chat` — there is no other
            | reliable way for a bot to learn a chat id it has not seen, and a
            | chat id typed by a user is never trusted.
            |
            | Unique per challenge rather than globally: the same group may host
            | two different challenges' announcements, while one challenge
            | posting twice into the same chat is the same feed twice.
            */
            $table->bigInteger('telegram_chat_id');
            $table->unique(['challenge_id', 'telegram_chat_id']);

            $table->string('chat_type', 32);

            // What the creator called the chat, echoed back in management copy.
            $table->string('title');

            // When each of the two admin checks last passed. Nullable because
            // they are facts observed over the Bot API, not attributes set at
            // insert time.
            $table->timestamp('bot_admin_verified_at')->nullable();
            $table->timestamp('creator_admin_verified_at')->nullable();

            /*
            | Off until both checks pass, and off again the moment a lazy
            | re-check fails. A deleted row would hide from the creator that
            | their broadcast surface stopped working and why.
            */
            $table->boolean('is_active')->default(false);

            /*
            | Privacy, separately defaulted (§2.6): sharing proof media into an
            | external chat is a bigger exposure step than showing proof to
            | fellow participants, so it gets its own opt-in on top of
            | `proof_is_public` — and cannot be enabled at all on a challenge
            | whose proofs are private.
            */
            $table->boolean('share_proof_media')->default(false);

            // The two broadcast kinds, both opt-in per chat.
            $table->boolean('post_checkin_announcements')->default(false);
            $table->boolean('post_daily_leaderboard')->default(false);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('challenge_chats');
    }
};
