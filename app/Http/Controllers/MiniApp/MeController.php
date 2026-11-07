<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Http\Resources\MiniApp\MiniAppUserResource;
use Illuminate\Http\Request;

/**
 * The authenticated Mini App's boot shape: who the token belongs to, and what
 * they can spend.
 *
 * The actor comes from the bearer token alone — `$request->user()` under
 * `auth:sanctum` — and never from a field on the request. This is the pattern
 * every later `/api/v1/miniapp/*` endpoint follows: re-resolve, re-check, and
 * only then act.
 *
 * The body is `MiniAppUserResource`, the very resource `/auth` nests under
 * `user`. This controller owns no shape of its own: it used to hand-build the
 * boot user a second time, side by side with the exchange, which is how the two
 * copies came to disagree. Returning the resource is also what puts `coins`
 * back within the client's reach on demand — `fetchMe()` re-reads it after an
 * action that can settle a balance, so the header is the ledger's answer rather
 * than the snapshot the app booted with.
 */
class MeController extends Controller
{
    public function __invoke(Request $request): MiniAppUserResource
    {
        return MiniAppUserResource::make($request->user());
    }
}
