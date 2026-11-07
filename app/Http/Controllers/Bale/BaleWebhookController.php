<?php

namespace App\Http\Controllers\Bale;

use App\Actions\Telegram\IngestTelegramUpdate;
use App\Enums\MessagingPlatform;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bale\BaleWebhookRequest;
use Illuminate\Http\JsonResponse;

/**
 * Bale's only way in.
 *
 * Same contract as the Telegram webhook, and deliberately the same shape:
 * record, queue, answer 200. Bale also treats a slow endpoint as a failing
 * one, so a controller that did real work would turn one slow database write
 * into a redelivery storm. Authenticity lives in `BaleWebhookRequest` (the
 * URL's secret path segment — Bale has no header mechanism), recording and
 * queueing in `IngestTelegramUpdate`, and every side effect in
 * `ProcessTelegramUpdate`.
 *
 * **A recording failure is deliberately not swallowed**, for the same reason
 * as on Telegram: a 200 on a database error would tell Bale the update
 * arrived, and it never redelivers what it believes was delivered.
 */
class BaleWebhookController extends Controller
{
    public function __invoke(BaleWebhookRequest $request, IngestTelegramUpdate $ingest): JsonResponse
    {
        $ingest->handle(MessagingPlatform::Bale, $request->integer('update_id'), $request->all());

        return response()->json(['ok' => true]);
    }
}
