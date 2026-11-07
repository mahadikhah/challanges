<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a user is inside a multi-step bot flow.
 *
 * The Telegram SDK has no FSM, so a wizard's position has to live somewhere
 * durable. `user_id` is unique: a user is in at most one flow at a time, so
 * starting a new wizard replaces the old one rather than leaving two half-built
 * challenges racing for the next message the user types.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('state', 64);

            /*
            | The answers gathered so far. Deliberately unstructured: the wizard
            | branches, so a column per step would be mostly-null and would need a
            | migration every time a question is added.
            */
            $table->json('payload')->nullable();

            /*
            | An abandoned wizard must not ambush the user weeks later, so state is
            | perishable. Nullable for a flow that should not expire.
            */
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            // The sweep that clears abandoned conversations.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_conversations');
    }
};
