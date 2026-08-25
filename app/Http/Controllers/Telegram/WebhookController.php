<?php

namespace App\Http\Controllers\Telegram;

use App\Actions\Telegram\IngestTelegramUpdate;
use App\Http\Controllers\Controller;
use App\Http\Requests\Telegram\WebhookRequest;
use Illuminate\Http\JsonResponse;

/**
 * Telegram's only way in.
 *
 * Thin to the point of being boring, and that is the requirement rather than a
 * style preference. Telegram allows one webhook call at a time per bot and treats
 * a slow endpoint as a failing one, so a controller that did real work would turn
 * one slow database write into a redelivery storm. Authenticity lives in
 * `WebhookRequest`, recording and queueing in `IngestTelegramUpdate`, and every
 * side effect in `ProcessTelegramUpdate`.
 *
 * **A recording failure is deliberately not swallowed.** Returning 200 on a
 * database error would tell Telegram the update arrived, and Telegram never
 * redelivers what it believes was delivered — the update would be gone for good.
 * Letting it surface as a 500 buys a redelivery, which is the only mechanism that
 * can recover it. Every other failure is already off the request in the queue, so
 * the only thing a non-2xx here can mean is "we genuinely do not have this yet".
 */
class WebhookController extends Controller
{
    public function __invoke(WebhookRequest $request, IngestTelegramUpdate $ingest): JsonResponse
    {
        $ingest->handle($request->integer('update_id'), $request->all());

        return response()->json(['ok' => true]);
    }
}
