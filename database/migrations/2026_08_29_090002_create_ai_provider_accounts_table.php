<?php

use App\Enums\AiLimitPeriod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The rotatable unit: one credential set plus what happened last time it
     * was used. Credentials live in the encrypted `config` column, never in
     * `.env`, so two vendors' keys can coexist under one capability.
     */
    public function up(): void
    {
        Schema::create('ai_provider_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_capability_id')->constrained('ai_capabilities')->cascadeOnDelete();
            $table->string('name');
            $table->string('driver')->nullable();
            $table->string('model')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('config')->nullable(); // encrypted:array cast on the model

            $table->unsignedBigInteger('input_token_limit')->nullable();
            $table->unsignedBigInteger('output_token_limit')->nullable();
            $table->unsignedBigInteger('total_token_limit')->nullable();
            $table->string('limit_period')->default(AiLimitPeriod::Monthly->value);
            $table->string('limit_timezone')->default('UTC');

            $table->unsignedBigInteger('input_token_price_per_million')->nullable();
            $table->unsignedBigInteger('output_token_price_per_million')->nullable();

            $table->datetime('unavailable_until')->nullable();
            $table->string('last_failure_reason')->nullable();
            $table->datetime('last_failed_at')->nullable();
            $table->datetime('last_succeeded_at')->nullable();
            $table->timestamps();

            $table->index(['ai_capability_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_provider_accounts');
    }
};
