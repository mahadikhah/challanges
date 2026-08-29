<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which messenger an update arrived through.
 *
 * `update_id` is unique *per platform*, not globally: Telegram and Bale each
 * number their own updates from zero-ish upward, and the two id spaces can
 * collide numerically without sharing anything. A global unique on
 * `update_id` would let one platform's delivery of update 500 suppress the
 * other platform's update 500 — a lost update that looks like idempotency
 * working. Scoping the index by platform keeps each platform's redelivery
 * deduplication exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table) {
            // Defaulted so existing rows file under Telegram without a
            // separate backfill pass; every insert names its platform.
            $table->string('platform', 16)->default('telegram')->after('id');
        });

        Schema::table('telegram_updates', function (Blueprint $table) {
            $table->dropUnique('telegram_updates_update_id_unique');
        });

        Schema::table('telegram_updates', function (Blueprint $table) {
            $table->unique(['platform', 'update_id']);
        });
    }

    public function down(): void
    {
        Schema::table('telegram_updates', function (Blueprint $table) {
            $table->dropUnique('telegram_updates_platform_update_id_unique');
        });

        Schema::table('telegram_updates', function (Blueprint $table) {
            $table->unique('update_id');
        });

        Schema::table('telegram_updates', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
