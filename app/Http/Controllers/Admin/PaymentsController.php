<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Payments\RefundStarsPayment;
use App\Enums\Contracts\HasTranslatedLabel;
use App\Http\Controllers\Controller;
use App\Models\StarPayment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;
use Telegram\Bot\Exceptions\TelegramSDKException;

/**
 * The Stars ledger's audit view and its one lever: the refund.
 *
 * Refunding goes through `RefundStarsPayment`, the same action any future
 * surface would call — Telegram first, then the row, then the clawback, so a
 * refusal leaves state that can be retried rather than half a refund. The
 * clawback is allowed to take a balance negative by design, which is why the
 * confirm copy on the panel says so before the admin commits.
 *
 * The action is method-injected for the same reason: it holds the Bot API
 * client, whose binding refuses to build without `TELEGRAM_BOT_TOKEN`, and the
 * audit listing must stay readable on a box where the token is not set — only
 * the refund itself needs Telegram.
 */
class PaymentsController extends Controller
{
    public function index(): Response
    {
        $page = StarPayment::query()
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->simplePaginate(20)
            ->withQueryString();

        return Inertia::render('Admin/Payments', [
            'payments' => $this->rows($page),
            'nextPageUrl' => $page->nextPageUrl(),
        ]);
    }

    public function refund(
        Request $request,
        StarPayment $payment,
        RefundStarsPayment $refund,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        try {
            $refund->handle($payment);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('admin.payments.refunded'),
            ]);
        } catch (LogicException) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('admin.payments.refund_refused'),
            ]);
        } catch (TelegramSDKException) {
            // No token on this box, or Telegram refused the call. Either way
            // the action left the row paid and retryable — the operator needs
            // the "it did not happen", not a stack trace.
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('admin.payments.refund_failed'),
            ]);
        }

        return back();
    }

    /**
     * @param  Paginator<int, StarPayment>  $page
     * @return list<array<string, mixed>>
     */
    private function rows(Paginator $page): array
    {
        $rows = [];

        foreach ($page->getCollection() as $payment) {
            $rows[] = [
                'id' => $payment->getKey(),
                'user' => $payment->user->first_name ?? $payment->user->name,
                'telegram_payment_charge_id' => $payment->telegram_payment_charge_id,
                'provider' => $this->enumShape($payment->provider),
                'stars_amount' => $payment->stars_amount,
                'rial_amount' => $payment->rial_amount,
                'coin_amount' => $payment->coin_amount,
                'status' => $this->enumShape($payment->status),
                'refundable' => $payment->isRefundable(),
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'refunded_at' => $payment->refunded_at?->toIso8601String(),
                'created_at' => $payment->created_at?->toIso8601String(),
            ];
        }

        return $rows;
    }

    /**
     * @param  \BackedEnum&HasTranslatedLabel  $case
     * @return array{value: string, label: string}
     */
    private function enumShape($case): array
    {
        return [
            'value' => (string) $case->value,
            'label' => $case->label(),
        ];
    }
}
