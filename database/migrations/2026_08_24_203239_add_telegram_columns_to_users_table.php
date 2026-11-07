<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give `users` its Telegram identity.
 *
 * There are two disjoint ways to be a user here and only one table for both: an
 * admin signs in with email + password through Fortify and may have no Telegram
 * account at all, while a bot user is Telegram-identity-only and has no
 * credentials to sign in with. `email` and `password` therefore become nullable
 * — a bot user has neither, and inventing a synthetic value would put a fake
 * address into a unique index and a login-shaped hole in the app.
 *
 * `email` keeps its unique index: MySQL permits many NULLs in one, so the
 * constraint still holds for everyone who actually has an address.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();

            /*
            | Telegram ids exceed 32 bits, so this has to be a bigint. Unique and
            | nullable for the same reason as `email`.
            */
            $table->unsignedBigInteger('telegram_id')->nullable()->unique()->after('id');

            // Indexed because admin search and @mention lookups start here.
            $table->string('telegram_username')->nullable()->index()->after('telegram_id');
            $table->string('first_name')->nullable()->after('name');

            /*
            | `language_code` is whatever Telegram reported (an IETF tag such as
            | `fa` or `en-US`); `locale` is the allowlisted locale we actually
            | serve, which the user may have overridden. Keeping both means a
            | deliberate choice is never silently undone by the next update.
            */
            $table->string('language_code', 16)->nullable()->after('first_name');
            $table->string('locale', 8)->nullable()->after('language_code');

            /*
            | Attribution for the invite that brought this user in. Nulled rather
            | than cascaded on delete: losing an inviter must not take the people
            | they invited with them.
            */
            $table->foreignId('referred_by_user_id')->nullable()->after('locale')
                ->constrained('users')->nullOnDelete();

            // Set once `getChatMember` confirms the announcement-channel join.
            $table->timestamp('channel_verified_at')->nullable()->after('email_verified_at');
            $table->boolean('is_admin')->default(false)->after('password');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_user_id');
            $table->dropColumn([
                'telegram_id',
                'telegram_username',
                'first_name',
                'language_code',
                'locale',
                'channel_verified_at',
                'is_admin',
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
