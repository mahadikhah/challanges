<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Phase 15 Task 1: the schema quantity scoring needs.
|
| `challenges` grows the scoring configuration, `check_ins` the per-period
| outcome, `challenge_participants` the running total. Everything is nullable
| or defaulted because every challenge until now — and every `binary` one
| after — must pass through untouched: the Action-level validation, not the
| DB, is what makes a quantity challenge carry its whole configuration, the
| same honest shape as the media caps.
|
| `scoring_strategy` is a fixed enum, never a formula string: a creator
| supplied expression evaluated server-side is a code-injection surface, and
| `proportional` covers the stated use case.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->string('scoring_type', 32)->default('binary')->after('flow_type');
            $table->decimal('target_value', 10, 2)->nullable()->after('scoring_type');
            $table->string('unit_label', 64)->nullable()->after('target_value');
            $table->string('scoring_strategy', 32)->nullable()->after('unit_label');
            $table->decimal('base_points', 10, 2)->nullable()->after('scoring_strategy');
            $table->boolean('quantity_partial_counts_as_done')->default(false)->after('base_points');
        });

        Schema::table('check_ins', function (Blueprint $table) {
            $table->decimal('reported_value', 10, 2)->nullable()->after('submitted_text');
            $table->decimal('score', 10, 2)->nullable()->after('reported_value');
        });

        Schema::table('challenge_participants', function (Blueprint $table) {
            $table->decimal('total_score', 12, 2)->default(0)->after('longest_streak');
        });
    }

    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->dropColumn([
                'scoring_type',
                'target_value',
                'unit_label',
                'scoring_strategy',
                'base_points',
                'quantity_partial_counts_as_done',
            ]);
        });

        Schema::table('check_ins', function (Blueprint $table) {
            $table->dropColumn(['reported_value', 'score']);
        });

        Schema::table('challenge_participants', function (Blueprint $table) {
            $table->dropColumn('total_score');
        });
    }
};
