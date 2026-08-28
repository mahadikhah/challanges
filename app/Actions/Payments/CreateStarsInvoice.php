<?php

namespace App\Actions\Payments;

use App\Enums\SettingKey;
use App\Enums\StarPaymentStatus;
use App\Messaging\Contracts\MessengerException;
use App\Messaging\Contracts\MessengerPlatform;
use App\Models\StarPayment;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Begin a Stars purchase: price it, record it, and ask Telegram for the link.
 *
 * The price never arrives from the client. A tap names a *package index* — which
 * row of the admin-tuned `stars_packages` table it means — and this action reads
 * the table itself, so a crafted payload cannot buy 620 coins for 50 Stars. The
 * row is written before the link is requested: when `successful_payment` comes
 * back, the row's own `stars_amount` and `coin_amount` are the amounts that get
 * compared and credited, not anything the client says.
 *
 * Every surface that sells coins goes through here, so the invoice's title and
 * description — what Telegram shows in the payment sheet — are resolved here
 * from `bot.shop.*` in the caller's locale rather than passed in per surface
 * and allowed to drift.
 *
 * A pending row whose link was never paid is not a failure — it is an abandoned
 * cart, and `Pending` is exactly what those look like.
 */
class CreateStarsInvoice
{
    public function __construct(
        private readonly Settings $settings,
        private readonly MessengerPlatform $platform,
    ) {}

    /**
     * @param  string  $locale  the payer's locale, for the invoice's own copy
     * @return StarsInvoice the recorded purchase and Telegram's link to it
     *
     * @throws InvalidArgumentException when no such package exists
     * @throws MessengerException when the platform refuses the invoice
     */
    public function handle(User $user, int $packageIndex, string $locale): StarsInvoice
    {
        $package = $this->settings->array(SettingKey::StarsPackages)[$packageIndex] ?? null;

        if (! is_array($package) || ! isset($package['stars'], $package['coins'])) {
            throw new InvalidArgumentException("There is no Stars package at index {$packageIndex}.");
        }

        $payment = StarPayment::query()->create([
            'user_id' => $user->getKey(),
            'invoice_payload' => 'coins:'.Str::lower(Str::random(24)),
            'stars_amount' => (int) $package['stars'],
            'coin_amount' => (int) $package['coins'],
            'status' => StarPaymentStatus::Pending,
        ]);

        $title = $this->line('bot.shop.invoice_title', ['coins' => $payment->coin_amount], $locale);

        $link = $this->platform->createInvoiceLink(
            $title,
            $this->line('bot.shop.invoice_description', [
                'app' => $this->line('common.app_name', [], $locale),
            ], $locale),

            // Bot-defined, opaque to the user, and what ties a later
            // `successful_payment` back to this row.
            $payment->invoice_payload,

            // CLAUDE.md, verified against core.telegram.org: XTR is the Stars
            // currency tag.
            'XTR',

            // Exactly one price line is required for Stars invoices.
            [['label' => $title, 'amount' => $payment->stars_amount]],
        );

        return new StarsInvoice($payment, $link);
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    private function line(string $key, array $replace, string $locale): string
    {
        $line = Lang::get($key, $replace, $locale);

        return is_string($line) ? $line : $key;
    }
}
