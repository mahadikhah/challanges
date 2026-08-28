<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The token lease held across a provider call. Budgets are enforced on the
     * *estimate* at reserve time, then swapped for real usage at reconcile.
     */
    public function up(): void
    {
        Schema::create('ai_usage_reservations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_capability_id')->nullable();
            $table->foreignId('ai_provider_account_id')->nullable();
            $table->nullableMorphs('owner');
            $table->nullableMorphs('subject');

            $table->string('operation');
            $table->string('idempotency_key')->unique();
            $table->string('job_identity')->nullable();
            $table->uuid('run_id')->nullable();

            $table->unsignedBigInteger('reserved_input_tokens')->default(0);
            $table->unsignedBigInteger('reserved_output_tokens')->default(0);
            $table->unsignedBigInteger('reserved_total_tokens')->default(0);
            $table->unsignedBigInteger('consumed_input_tokens')->nullable();
            $table->unsignedBigInteger('consumed_output_tokens')->nullable();
            $table->unsignedBigInteger('consumed_total_tokens')->nullable();
            $table->unsignedBigInteger('released_input_tokens')->nullable();
            $table->unsignedBigInteger('released_output_tokens')->nullable();
            $table->unsignedBigInteger('released_total_tokens')->nullable();

            $table->string('status')->default('queued');
            $table->boolean('usage_known')->default(false);
            $table->foreignId('ai_usage_record_id')->nullable()->constrained('ai_usage_records')->nullOnDelete();

            $table->datetime('started_at')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->datetime('failed_at')->nullable();
            $table->datetime('released_at')->nullable();
            $table->datetime('reconciled_at')->nullable();
            $table->datetime('terminal_at')->nullable();
            $table->timestamps();

            $table->index(['ai_provider_account_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_reservations');
    }
};
