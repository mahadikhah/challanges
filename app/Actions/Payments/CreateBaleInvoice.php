<?php

namespace App\Actions\Payments;

use App\Enums\PaymentProvider;
use App\Enums\SettingKey;
use App\Enums\StarPaymentStatus;
use App\Messaging\Contracts\MessengerException;
use App\Messaging\PlatformRegistry;
use App\Models\StarPayment;
use App\Models\User;
use App\Services\Settings;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Begin a Bale Pay purchase: price it, record it, and put the invoice in the
 * payer's chat.
 *
 * The sibling of `CreateStarsInvoice`, and the differences are the rail's own:
 * Bale has no payable link (the `bale-payments` skill's trap #1), so the
 * invoice is *sent* into the chat rather than answered with a link button;
 * and it prices in Rial, so the package row's optional `rial` key is the
 * shelf's price — a package without one is not on Bale's shelves at all.
 *
 * As on the Stars side, the price never arrives from the client. A tap names a
 * package *index*; this action re-reads the admin-tuned table and writes the
 * row before any invoice exists, so what later gets verified and credited is
 * the row's own `rial_amount` and `coin_amount`, never anything the update
 * carries.
 */
class CreateBaleInvoice
{
    public function __construct(
        private readonly Settings $settings,
        private readonly PlatformRegistry $platforms,
    ) {}

    /**
     * @param  string  $locale  the payer's locale, for the invoice's own copy
     * @return StarPayment the recorded purchase; the invoice message is already in the payer's chat
     *
     * @throws InvalidArgumentException when no such package is priced in Rial
     * @throws MessengerException when Bale refuses the invoice
     */
    public function handle(User $user, int $packageIndex, string $locale): StarPayment
    {
        $package = $this->settings->array(SettingKey::StarsPackages)[$packageIndex] ?? null;

        // `is_numeric` rather than `is_int`: a JSON round-trip may hand the
        // admin's number back as a string, and the row wants the integer.
        $rial = is_array($package) && is_numeric($package['rial'] ?? null)
            ? (int) $package['rial']
            : null;

        if (! is_array($package) || ! isset($package['coins']) || $rial === null || $rial <= 0) {
            throw new InvalidArgumentException("There is no Bale-priced package at index {$packageIndex}.");
        }

        $payment = StarPayment::query()->create([
            'user_id' => $user->getKey(),
            'provider' => PaymentProvider::BalePay,
            'invoice_payload' => 'coins:'.Str::lower(Str::random(24)),

            // Stars and Rial are not exchange rates of each other; a Bale row
            // carries only the Rial price and leaves the Stars column empty.
            'stars_amount' => null,
            'rial_amount' => $rial,
            'coin_amount' => (int) $package['coins'],
            'status' => StarPaymentStatus::Pending,
        ]);

        $title = $this->line('bot.shop.invoice_title', ['coins' => $payment->coin_amount], $locale);

        // The payer's chat is where a Bale invoice can exist at all, and for a
        // private chat that id is the user's own platform id.
        $chatId = $user->platform_user_id;

        if ($chatId === null) {
            throw new MessengerException(
                "User {$user->getKey()} has no Bale chat id, so no invoice can reach them."
            );
        }

        $this->platforms->for($user->platform)->sendInvoice(
            $chatId,
            $title,
            $this->line('bot.shop.invoice_description', [
                'app' => $this->line('common.app_name', [], $locale),
            ], $locale),

            // Bot-defined, opaque to the user, and what ties the later
            // `successful_payment` back to this row.
            $payment->invoice_payload,

            // Rial, one price line — the same shape the Stars rail uses.
            [['label' => $title, 'amount' => $rial]],
        );

        return $payment;
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
