<?php

namespace App\Services\Telegram\Handlers;

use App\Actions\Telegram\ResolveTelegramUser;
use App\Models\StarPayment;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Localization;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\HandlesUpdate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

/**
 * The checkpoint before the money moves: Telegram's `pre_checkout_query`.
 *
 * Telegram holds the payment sheet open for ten seconds and demands an answer,
 * so this handler does one indexed lookup and answers — it never sends a
 * message, never credits anything, and never declines *silently*, because an
 * unanswered query is a payment that hangs until it times out.
 *
 * What it checks, in the order that matters:
 *
 * 1. **Whose invoice this is.** The payer is resolved from `from` against our
 *    own rows, and the invoice is looked up scoped to that user — a payload
 *    learned from somebody else's link is declined, not credited to its owner.
 * 2. **What it costs.** The currency must be `XTR` and the total must equal the
 *    row's own `stars_amount`, the price we set when the link was issued.
 *
 * A decline is not a judgement on the row: the invoice stays `Pending`, because
 * a decline at pre-checkout proves nothing about the invoice itself (the query
 * may be stale, replayed, or malformed) and marking it `Failed` would kill a
 * link the user could still legitimately pay.
 */
class PreCheckoutQueryHandler implements HandlesUpdate
{
    public function __construct(
        private readonly ResolveTelegramUser $resolveUser,
        private readonly BotMessenger $messenger,
        private readonly Localization $localization,
        private readonly Api $telegram,
    ) {}

    public function handle(TelegramUpdate $update): void
    {
        $queryId = $update->value('pre_checkout_query.id');

        if (! is_string($queryId) || $queryId === '') {
            // Nothing to answer, so nothing to do but notice it happened.
            Log::info('A pre_checkout_query arrived without a query id.', [
                'update_id' => $update->update_id,
            ]);

            return;
        }

        $from = $update->value('pre_checkout_query.from');

        if (! is_array($from) || $update->value('pre_checkout_query.from.is_bot') === true) {
            $this->decline($queryId, null);

            return;
        }

        /** @var array<string, mixed> $from */
        $user = $this->resolveUser->handle($from);

        $invoicePayload = $update->value('pre_checkout_query.invoice_payload');

        $payment = is_string($invoicePayload) && $invoicePayload !== ''
            ? StarPayment::query()
                ->where('invoice_payload', $invoicePayload)
                ->where('user_id', $user->getKey())
                ->first()
            : null;

        $currency = $update->value('pre_checkout_query.currency');
        $totalAmount = $update->value('pre_checkout_query.total_amount');

        $acceptable = $payment !== null
            && $payment->status->isPaid() === false
            && $payment->status->isTerminal() === false
            && $currency === 'XTR'
            && $totalAmount === $payment->stars_amount;

        if ($acceptable) {
            $this->telegram->answerPreCheckoutQuery([
                'pre_checkout_query_id' => $queryId,
                'ok' => true,
            ]);

            return;
        }

        Log::warning('A pre_checkout_query was declined.', [
            'update_id' => $update->update_id,
            'user_id' => $user->getKey(),
            'invoice_payload' => is_string($invoicePayload) ? $invoicePayload : null,
        ]);

        $this->decline($queryId, $user);
    }

    /**
     * Answer "no", with a message in the payer's own language — this is the one
     * string of the payment flow the user reads inside Telegram's sheet.
     */
    private function decline(string $queryId, ?User $user): void
    {
        $locale = $user !== null
            ? $this->messenger->localeFor($user)
            : $this->localization->fallback();

        $line = Lang::get('bot.shop.pre_checkout_error', [], $locale);

        $this->telegram->answerPreCheckoutQuery([
            'pre_checkout_query_id' => $queryId,
            'ok' => false,
            'error_message' => is_string($line) ? $line : 'bot.shop.pre_checkout_error',
        ]);
    }
}
