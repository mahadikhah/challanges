<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_approval_decisions', function (Blueprint $table): void {
            // Nullable on purpose: the rows Phase 10 wrote for photos predate
            // the distinction, and history is not back-filled into a lie.
            $table->string('review_path', 32)->nullable()->after('model');
            // The transcript a voice review judged — audit and display data
            // only, exactly like `reason` and `raw_response` (§2.8): nothing
            // may evaluate or act on its contents.
            $table->text('transcript')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('ai_approval_decisions', function (Blueprint $table): void {
            $table->dropColumn(['review_path', 'transcript']);
        });
    }
};
