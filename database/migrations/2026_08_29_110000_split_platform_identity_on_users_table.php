<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give `users` a platform-agnostic messenger identity.
 *
 * Phase 11 Task 1: `telegram_id` becomes the pair `platform` +
 * `platform_user_id`, so a second messenger (Bale, next task) is a new enum
 * case and new config — not a new column set. Every existing row is Telegram,
 * so the data migration is a backfill, not a transform.
 *
 * The unique index moves from `telegram_id` alone to the composite
 * (`platform`, `platform_user_id`): Telegram and Bale both draw from numeric
 * id spaces that can collide with each other, so uniqueness has to be scoped
 * by platform. Telegram ids remain valid as their platform's user ids.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('platform', 16)->nullable()->after('id');
            $table->unsignedBigInteger('platform_user_id')->nullable()->after('platform');
        });

        // Real data migration: every existing messenger user is Telegram. A
        // plain UPDATE in the same migration, rather than a seeded value or a
        // post-deploy script, so there is no window in which a row has a
        // messenger id and no platform to read it against.
        DB::table('users')
            ->whereNotNull('telegram_id')
            ->update([
                'platform' => 'telegram',
                'platform_user_id' => DB::raw('`telegram_id`'),
            ]);

        Schema::table('users', function (Blueprint $table) {
            $table->unique(['platform', 'platform_user_id']);
            $table->dropUnique('users_telegram_id_unique');
            $table->dropColumn('telegram_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_id')->nullable()->after('id');
        });

        DB::table('users')
            ->where('platform', 'telegram')
            ->update(['telegram_id' => DB::raw('platform_user_id')]);

        Schema::table('users', function (Blueprint $table) {
            $table->unique('telegram_id');
            $table->dropUnique('users_platform_platform_user_id_unique');
            $table->dropColumn(['platform', 'platform_user_id']);
        });
    }
};
