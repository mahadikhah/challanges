<?php

namespace App\Http\Requests\Telegram;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Authenticity and shape of an inbound webhook call.
 *
 * The webhook is a public URL that mutates state, so it carries **two**
 * independent secrets and both must match:
 *
 * 1. A secret path segment, set when the webhook URL is registered.
 * 2. The `X-Telegram-Bot-Api-Secret-Token` header, which Telegram echoes back
 *    from the `secret_token` given to `setWebhook`.
 *
 * They are separate values on purpose. A URL ends up in reverse-proxy access
 * logs, in a screenshot of a `setWebhook` call, in shell history; a header does
 * not. Reusing one value for both would throw away the reason Telegram added
 * `secret_token` on top of "put a secret in your path" — with two, a leaked URL
 * alone still cannot forge an update.
 *
 * **Fail closed.** An unset secret refuses everything rather than waving
 * everything through, because the failure mode of the alternative is an open
 * write endpoint that looks like it is working.
 */
class WebhookRequest extends FormRequest
{
    /**
     * Verify both secrets, in constant time.
     *
     * Answers **404**, not 403: a probe should not be able to tell a real webhook
     * path with a wrong secret from a path that was never routed. Telegram
     * retries any non-2xx, so a genuinely stale `secret_token` still recovers on
     * its own once the configuration is fixed.
     */
    public function authorize(): bool
    {
        $failed = $this->failedCheck();

        if ($failed !== null) {
            // Logged rather than returned: an operator debugging a silent bot
            // needs to know which half is wrong, and a caller does not.
            Log::warning('Rejected a Telegram webhook call.', [
                'check' => $failed,
                'ip' => $this->ip(),
            ]);

            throw new NotFoundHttpException;
        }

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // Telegram sends this on every update, and it is the idempotency key
            // for the whole pipeline. A body without one is not an update.
            'update_id' => ['required', 'integer'],
        ];
    }

    /**
     * Which secret check failed, or null when both passed.
     */
    private function failedCheck(): ?string
    {
        $pathSecret = (string) config('services.telegram.webhook_secret');
        $headerSecret = (string) config('services.telegram.webhook_header_secret');

        if ($pathSecret === '' || $headerSecret === '') {
            return 'unconfigured';
        }

        if (! hash_equals($pathSecret, (string) $this->route('token'))) {
            return 'path';
        }

        if (! hash_equals($headerSecret, (string) $this->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return 'header';
        }

        return null;
    }
}
