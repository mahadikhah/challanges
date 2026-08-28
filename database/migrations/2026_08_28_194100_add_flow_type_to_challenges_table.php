<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timed sessions are opt-in: every existing challenge is a `simple` one, and
 * the default keeps it that way for anything that never says otherwise.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->string('flow_type', 32)->default('simple')->after('proof_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->dropColumn('flow_type');
        });
    }
};
