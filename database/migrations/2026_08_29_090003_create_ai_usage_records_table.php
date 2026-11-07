<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The append-only ledger: one row per provider attempt, success or
     * failure. Terminal rows refuse updates and deletes at the model layer.
     */
    public function up(): void
    {
        Schema::create('ai_usage_records', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('owner');
            $table->nullableMorphs('subject');
            $table->foreignId('ai_capability_id')->nullable();
            $table->foreignId('ai_provider_account_id')->nullable();

            $table->string('entry_type')->default('usage'); // usage | adjustment
            $table->string('adjustment_direction')->nullable(); // credit | debit
            $table->foreignId('adjusts_ai_usage_record_id')->nullable()->constrained('ai_usage_records')->nullOnDelete();

            $table->string('operation');
            $table->string('outcome');
            $table->string('idempotency_key')->unique();
            $table->string('job_identity')->nullable();
            $table->uuid('run_id')->nullable();

            $table->string('driver')->nullable();
            $table->string('model')->nullable();

            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('total_tokens')->default(0);
            $table->unsignedBigInteger('provider_reported_total_tokens')->nullable();

            $table->unsignedBigInteger('input_token_rate_per_million')->nullable();
            $table->unsignedBigInteger('output_token_rate_per_million')->nullable();
            $table->unsignedBigInteger('estimated_cost_minor')->nullable();

            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('status')->default('pending');
            $table->string('usage_quality')->nullable();
            $table->json('metadata')->nullable();

            $table->datetime('provider_called_at')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->timestamps();

            $table->index(['ai_provider_account_id', 'created_at']);
            $table->index(['driver', 'created_at']);
            $table->index(['operation', 'status', 'created_at']);
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_records');
    }
};
