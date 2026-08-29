<?php

use App\Enums\PaymentProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Phase 11 Task 3: the payments table grows a second rail.
|
| `provider` names the rail a row was issued on — Telegram Stars until now,
| Bale Pay from here — and `rial_amount` carries Bale's price, which is not
| convertible to Stars (Stars are XTR, Bale charges Rial), so the two prices
| are separate columns rather than one reused one. Telegram rows leave
| `rial_amount` null exactly as Bale rows leave `stars_amount` null.
|
| Existing rows default to Telegram Stars, which is the only rail that could
| have written them.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('star_payments', function (Blueprint $table) {
            $table->string('provider', 32)->default(PaymentProvider::TelegramStars->value)->after('user_id');
            $table->unsignedBigInteger('rial_amount')->nullable()->after('stars_amount');

            // A Bale row carries only the Rial price; a Stars row only the XTR
            // one. Exactly one of the two is set, per `provider`.
            $table->unsignedBigInteger('stars_amount')->nullable()->change();

            $table->index(['provider', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('star_payments', function (Blueprint $table) {
            $table->dropIndex(['provider', 'status']);
            $table->dropColumn(['provider', 'rial_amount']);
            $table->unsignedBigInteger('stars_amount')->nullable(false)->change();
        });
    }
};
