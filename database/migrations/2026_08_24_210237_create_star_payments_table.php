<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Telegram Stars purchase, from invoice link to credited coins.
 *
 * A row is written when the invoice is created, so a `successful_payment` update
 * always has somewhere to land and can be matched back to the package that was
 * offered — rather than trusting whatever amount the update happens to carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('star_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
            | Telegram's own id for the charge, and the idempotency key for
            | crediting. Null until the payment succeeds; MySQL allows repeated
            | NULLs under a unique index, so every pending row can coexist.
            */
            $table->string('telegram_payment_charge_id', 191)->nullable()->unique();

            /*
            | Our payload, echoed back by Telegram in both `pre_checkout_query`
            | and `successful_payment`. Unique because it is how we find this row
            | again — the update carries no id of ours other than this.
            */
            $table->string('invoice_payload', 191)->unique();

            $table->unsignedInteger('stars_amount');

            /*
            | Priced at invoice time from `stars_packages`. Frozen here so a later
            | change to the package list cannot retroactively alter what an
            | already-paid purchase was worth.
            */
            $table->unsignedInteger('coin_amount');

            $table->string('status', 32);

            /*
            | The raw update, kept verbatim. Payment disputes are argued from what
            | Telegram actually sent, not from our parse of it.
            */
            $table->json('payload')->nullable();

            /*
            | Beyond the field list in CLAUDE.md: these make the lifecycle
            | queryable, and make the refund path idempotent without having to
            | infer "already refunded" from the status alone.
            */
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('star_payments');
    }
};
