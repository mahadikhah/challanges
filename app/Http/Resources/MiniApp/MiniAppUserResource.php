<?php

namespace App\Http\Resources\MiniApp;

use App\Models\User;
use App\Services\CoinLedger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The Mini App's boot user: who the token belongs to, and what they can spend.
 *
 * This is the one place the shape is written down. `/auth` nests it under
 * `user` and `/me` answers with it as the whole body, so the two cannot drift —
 * the header that rendered "undefined coins" was two hand-built copies of this
 * contract, and only one of them carried the field.
 *
 * `coins` is read, never stored: `CoinLedger::balanceFor()` is the only balance
 * reader and `users` has no such column. `toArray()` always emits it, so no
 * construction path — `new`, `::make()`, `::collection()`, or an entry in a
 * caller's own envelope — can produce this shape without it.
 *
 * @mixin User
 */
class MiniAppUserResource extends JsonResource
{
    /**
     * @return array{id: int, first_name: string|null, username: string|null, locale: string|null, coins: int, bot_url: string|null}
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;

        return [
            'id' => $user->getKey(),
            'first_name' => $user->first_name,
            'username' => $user->telegram_username,
            'locale' => $user->locale,
            'coins' => $this->ledger()->balanceFor($user),

            // Where the Mini App sends somebody whose proof only exists in the
            // bot (a photo, a recording), named by the platform they actually
            // stand on — never a hardcoded t.me username. Null for a row with
            // no platform, which a bot user cannot have, and for a platform
            // whose username is not configured. Task 6's options payload must
            // read this same helper rather than re-derive it.
            'bot_url' => $user->platform?->botUrl(),
        ];
    }

    /**
     * The balance reader.
     *
     * Located from the container rather than injected, and that is forced
     * rather than preferred: `JsonResource::__construct($resource)` takes
     * exactly one argument, `make()` is `new static(...$parameters)`, and a
     * collection builds each member with a single argument — so a resource
     * constructor can never receive a dependency. Staying one-argument is also
     * what keeps `new`, `::make()` and `::collection()` all working, which the
     * next endpoint to return a boot user will rely on.
     *
     * The `@var User` local above is load-bearing for the same reason: the
     * wrapped `$this->resource` is `mixed`, and phpstan runs at level 7.
     */
    private function ledger(): CoinLedger
    {
        return app(CoinLedger::class);
    }
}
