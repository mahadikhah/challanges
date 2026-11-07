<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make "no two participants in a period share a phrase" a property of the
 * database rather than of the code that happens to write it.
 *
 * The `text_autogen` proof type only works because a phrase cannot be passed to
 * the person next to you. That guarantee is worth more than a generator's good
 * intentions: with the index in place a duplicate is rejected and re-rolled, and
 * a future bug that narrows the vocabulary fails loudly instead of quietly
 * handing everyone the same words.
 *
 * NULLs are distinct in a MySQL unique index, so the many rows that never carry a
 * phrase — every `button` and `image_approval` challenge — are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('check_ins', function (Blueprint $table): void {
            $table->unique(['challenge_period_id', 'expected_phrase'], 'check_ins_period_phrase_unique');
        });
    }

    public function down(): void
    {
        Schema::table('check_ins', function (Blueprint $table): void {
            $table->dropUnique('check_ins_period_phrase_unique');
        });
    }
};
