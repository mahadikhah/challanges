<?php

namespace App\Services\Telegram\Callbacks;

use App\Actions\Payments\CreateStarsInvoice;
use App\Actions\Telegram\VerifyChannelMembership;
use App\Models\User;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\ChannelGatePrompt;
use App\Services\Telegram\HandlesCallback;
use InvalidArgumentException;

/**
 * Delivers a package-button tap to the invoice counter.
 *
 * The button carries an index and nothing else — no price, no amount, no user
 * id. `CreateStarsInvoice` re-reads the package table under that index and
 * prices the row itself, so what the tap buys is decided server-side at the
 * moment of the invoice, not at the moment the keyboard was built. An index
 * that no longer names a package (an admin removed it since the list was sent)
 * reads as a stale button, which is what it now is.
 */
class ShopCallback implements HandlesCallback
{
    /**
     * The action word on a package button.
     */
    public const ACTION = 'sp';

    public function __construct(
        private readonly CreateStarsInvoice $invoices,
        private readonly VerifyChannelMembership $gate,
        private readonly ChannelGatePrompt $gatePrompt,
        private readonly BotMessenger $messenger,
    ) {}

    public function handle(User $user, BotCallback $callback): void
    {
        if (! $this->gate->ensure($user)) {
            $this->gatePrompt->send($user);

            return;
        }

        $index = $callback->argument(0);

        if ($index === null || ! ctype_digit($index)) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.fallback.stale_button'));

            return;
        }

        try {
            $invoice = $this->invoices->handle(
                $user,
                (int) $index,
                $this->messenger->localeFor($user),
            );
        } catch (InvalidArgumentException) {
            // A package that is no longer in the table. The message the button
            // rode on is still in the chat, so say something rather than leave
            // a tap that visibly does nothing.
            $this->messenger->send($user, $this->messenger->line($user, 'bot.fallback.stale_button'));

            return;
        }

        $this->messenger->paragraphs(
            $user,
            [$this->messenger->line($user, 'bot.shop.pay_prompt', [
                'stars' => $invoice->payment->stars_amount,
                'coins' => $invoice->payment->coin_amount,
            ])],
            [[[
                // A URL button, because paying happens in Telegram's own
                // invoice sheet, which only a link can open.
                'text' => $this->messenger->line($user, 'bot.shop.pay_button', [
                    'stars' => $invoice->payment->stars_amount,
                ]),
                'url' => $invoice->link,
            ]]],
        );
    }
}
