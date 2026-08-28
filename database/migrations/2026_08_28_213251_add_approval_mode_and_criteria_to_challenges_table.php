<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            // Existing image-approval challenges keep their creator as the
            // reviewer; nothing about the column's presence changes them.
            $table->string('approval_mode', 20)->default('manual')->after('proof_type');
            $table->text('approval_criteria')->nullable()->after('approval_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->dropColumn(['approval_mode', 'approval_criteria']);
        });
    }
};
