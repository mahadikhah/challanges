<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which messenger a linked chat lives on.
 *
 * A `ChallengeChat` row carries a chat id issued by one platform, and every
 * later post into that chat must go through the same platform's bot — a Bale
 * chat id means nothing to the Telegram bot and vice versa. The same
 * reasoning widens the per-challenge unique index: chat ids are
 * platform-issued and can collide numerically across platforms, so "one
 * challenge posting twice into the same chat" is per platform, not global.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challenge_chats', function (Blueprint $table) {
            // Defaulted so existing rows file under Telegram without a
            // separate backfill pass; registration names the platform.
            $table->string('platform', 16)->default('telegram')->after('challenge_id');
        });

        // Add the wider index *before* dropping the narrower one: MySQL refuses
        // to drop an index a foreign key still needs, and the
        // `challenge_id` foreign key rides on the old unique until the new
        // one (also leftmost on `challenge_id`) exists to carry it.
        Schema::table('challenge_chats', function (Blueprint $table) {
            $table->unique(['challenge_id', 'platform', 'telegram_chat_id']);
        });

        Schema::table('challenge_chats', function (Blueprint $table) {
            $table->dropUnique('challenge_chats_challenge_id_telegram_chat_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('challenge_chats', function (Blueprint $table) {
            $table->dropUnique('challenge_chats_challenge_id_platform_telegram_chat_id_unique');
        });

        Schema::table('challenge_chats', function (Blueprint $table) {
            $table->unique(['challenge_id', 'telegram_chat_id']);
        });

        Schema::table('challenge_chats', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
