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
        Schema::create('ai_approval_decisions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('check_in_id')->constrained()->cascadeOnDelete();

            // Which account actually answered, resolved from the connection
            // name the SDK reported — the same provenance rule as the usage
            // records. Both null when no account answered at all.
            $table->string('connection')->nullable();
            $table->string('model')->nullable();

            // `applied` — confidence cleared the threshold and the verdict
            // took effect. `fell_back` — low confidence, unreadable response,
            // or no provider answered; the row sits in the manual queue.
            $table->string('outcome', 20);

            // True = approved, false = rejected, null = no decision was
            // reached (provider failure or unparseable response).
            $table->boolean('approved')->nullable();

            // 0–100, what the model itself claimed. Nullable for the same
            // reason `approved` is.
            $table->decimal('confidence', 5, 2)->nullable();

            // The model's stated reason, or the failure's. Display data only —
            // nothing anywhere may act on this string (§2.8).
            $table->text('reason')->nullable();

            $table->unsignedInteger('latency_ms')->nullable();

            // The response the decision was read from, truncated. Enough to
            // audit what the model actually said; never the whole payload.
            $table->text('raw_response')->nullable();

            $table->timestamps();

            $table->index(['check_in_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_approval_decisions');
    }
};
