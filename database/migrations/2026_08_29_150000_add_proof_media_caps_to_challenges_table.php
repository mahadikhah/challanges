<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Phase 14 Task 1: challenges grow the media caps voice/video proof needs.
|
| `proof_media_max_seconds` and `proof_media_max_size_kb` are nullable because
| the challenges that existed before voice/video — and every `button`,
| `text_autogen` and `image_approval` challenge after them — have no duration
| to cap and have run fine without a size cap. They become required the moment
| the challenge asks for a recording, and the creator's value is bounded
| server-side by the admin ceiling Settings, so a nullable column with an
| Action-level floor (not a DB one) is the honest shape.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->unsignedInteger('proof_media_max_seconds')->nullable()->after('proof_is_public');
            $table->unsignedInteger('proof_media_max_size_kb')->nullable()->after('proof_media_max_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->dropColumn(['proof_media_max_seconds', 'proof_media_max_size_kb']);
        });
    }
};
