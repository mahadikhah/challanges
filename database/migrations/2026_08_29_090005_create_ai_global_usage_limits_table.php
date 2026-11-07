<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An explicit budget window, app-wide or per-owner. Explicit dates rather
     * than a rolling period: a real budget is "this billing period", a thing
     * with dates someone agreed to.
     */
    public function up(): void
    {
        Schema::create('ai_global_usage_limits', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('owner');
            $table->datetime('starts_at');
            $table->datetime('ends_at');
            $table->string('timezone')->default('UTC');
            $table->unsignedBigInteger('input_token_limit')->nullable();
            $table->unsignedBigInteger('output_token_limit')->nullable();
            $table->unsignedBigInteger('total_token_limit')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'is_active', 'starts_at', 'ends_at'], 'ai_global_limits_window_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_global_usage_limits');
    }
};
