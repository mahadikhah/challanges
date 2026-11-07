<?php

namespace App\Http\Requests\Bale;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Authenticity and shape of an inbound Bale webhook call.
 *
 * Bale's webhook offers **no** signature and no secret token — `setWebhook`
 * accepts only a URL (verified against docs.bale.ai; see
 * progress-phase-11.md). There is no header to check, so the secret path
 * segment of the registered URL is the *only* authenticator, which is why it
 * must be long and random: it is the whole gate, not one of two factors as on
 * Telegram.
 *
 * Everything a payload claims is still re-verified downstream (the actor is
 * resolved from our own rows; chat ids are never read from the payload for
 * delivery), so a forged update that beat the URL secret still could not make
 * the bot speak as or to somebody else.
 *
 * **Fail closed.** An unset secret refuses everything rather than waving
 * everything through, because the failure mode of the alternative is an open
 * write endpoint that looks like it is working.
 */
class BaleWebhookRequest extends FormRequest
{
    /**
     * Verify the path secret, in constant time.
     *
     * Answers **404**, not 403, for the same reason the Telegram webhook does:
     * a probe should not be able to tell a real webhook path with a wrong
     * secret from a path that was never routed.
     */
    public function authorize(): bool
    {
        $failed = $this->failedCheck();

        if ($failed !== null) {
            Log::warning('Rejected a Bale webhook call.', [
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
            // Bale numbers its updates exactly as Telegram does, and this is
            // the idempotency key for the whole pipeline. A body without one
            // is not an update.
            'update_id' => ['required', 'integer'],
        ];
    }

    /**
     * Which secret check failed, or null when it passed.
     */
    private function failedCheck(): ?string
    {
        $pathSecret = (string) config('services.bale.webhook_secret');

        if ($pathSecret === '') {
            return 'unconfigured';
        }

        if (! hash_equals($pathSecret, (string) $this->route('token'))) {
            return 'path';
        }

        return null;
    }
}
