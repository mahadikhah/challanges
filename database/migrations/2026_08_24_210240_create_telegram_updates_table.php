<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every webhook update Telegram has sent us, recorded before anything acts on it.
 *
 * `update_id` is unique, which is the whole mechanism: Telegram retries any
 * non-2xx response, so the same update will arrive again. The insert either wins
 * or collides, and a collision means "already accepted" — no processing needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_updates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('update_id')->unique();
            $table->json('payload');

            /*
            | Set by the queued job once handled. A null here after the fact means
            | the job never completed, which is a lead worth following rather than
            | a state to overwrite.
            */
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['processed_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }
};
