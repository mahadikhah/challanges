<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Services\CoinLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The authenticated Mini App's boot shape: who the token belongs to, and what
 * they can spend.
 *
 * The actor comes from the bearer token alone — `$request->user()` under
 * `auth:sanctum` — and never from a field on the request. This is the pattern
 * every later `/api/v1/miniapp/*` endpoint follows: re-resolve, re-check, and
 * only then act.
 */
class MeController extends Controller
{
    public function __construct(private readonly CoinLedger $ledger) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'id' => $user->getKey(),
            'first_name' => $user->first_name,
            'username' => $user->telegram_username,
            'locale' => $user->locale,
            'coins' => $this->ledger->balanceFor($user),
        ]);
    }
}
