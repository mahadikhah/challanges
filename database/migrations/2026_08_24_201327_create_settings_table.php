<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A row here is an *override*: the App\Enums\SettingKey case carries the
     * default, so the platform is fully functional with this table empty and a
     * newly added tunable needs no backfill migration. There is deliberately no
     * `type` column — the enum already declares each key's shape, and a second
     * copy in the database could only ever drift from it.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
