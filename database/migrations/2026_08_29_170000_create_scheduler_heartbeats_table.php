<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Phase 13 Task 3: the scheduler's dead-man's-switch record.
|
| One row, not a cache entry and not a `settings` override. The database cache
| driver's rows disappear on any `cache:clear` — which is exactly when someone
| is debugging and least able to shrug off a false "the scheduler never ran" —
| and a `settings` row is an admin-tunable, not runtime state. A table of its
| own survives both, and Tasks 4 and 5 read it directly.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduler_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->timestamp('last_ran_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_heartbeats');
    }
};
