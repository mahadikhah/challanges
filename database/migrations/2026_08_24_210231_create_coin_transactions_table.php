<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The coin ledger — append-only, and the only truth about a balance.
 *
 * There is deliberately no `users.coin_balance` column to drift out of step.
 * `balance_after` is a running snapshot written under the row lock, so a
 * reconciliation job can prove the chain sums correctly rather than assume it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
            | Signed: negative is a debit. The sign is derived from the reason by
            | `CoinLedger`, never supplied by a caller.
            */
            $table->integer('amount');
            $table->string('reason', 48);
            $table->integer('balance_after');

            $table->nullableMorphs('reference');

            /*
            | Required, unique, and the whole point of this table: a retried
            | webhook, a double-tapped button and a re-run job all collapse onto
            | one row instead of paying out twice.
            */
            $table->string('idempotency_key', 191)->unique();

            $table->timestamps();

            // The statement history, newest first.
            $table->index(['user_id', 'id']);
            $table->index(['user_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_transactions');
    }
};
