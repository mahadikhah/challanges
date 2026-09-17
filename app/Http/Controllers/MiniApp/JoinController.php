<?php

namespace App\Http\Controllers\MiniApp;

use App\Actions\Challenges\JoinChallenge;
use App\Actions\Challenges\ResolveViewableChallenge;
use App\Actions\Entitlements\PurchaseEntitlement;
use App\Actions\Telegram\VerifyChannelMembership;
use App\Exceptions\ChallengeNotJoinableException;
use App\Exceptions\NoEntitlementAvailableException;
use App\Http\Controllers\Controller;
use App\Http\Resources\MiniApp\ChallengeResource;
use App\Models\User;
use App\Services\CoinLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Mini App's join button.
 *
 * It calls the same `JoinChallenge` action the bot's join callback does, and
 * carries **no join rule of its own** — no slot check, no status check, no
 * timeline check. Three things follow from that and are the reason to check
 * rather than assume:
 *
 * - **Joining twice costs one slot.** `JoinChallenge` resolves an existing
 *   participation before it spends anything, so a double-tapped button and a
 *   Telegram retry both arrive here safely. The status code says which happened
 *   (201 created, 200 you were already in) but nothing is charged twice.
 * - **The refusal comes from the action, not from here.** A closed challenge and
 *   a spent timeline are `ChallengeNotJoinableException`'s two reasons; a
 *   participation that already ended is its third.
 * - **The one thing this endpoint adds is the price quote.** A user with no join
 *   slot is told what another costs and what they hold, because "no" without a
 *   number is a wall. Buying stays in the bot — the shop is a later phase.
 *
 * The channel gate is re-verified here, not at auth: CLAUDE.md pins "re-verify
 * on privileged actions", and joining spends a slot. Same call, in the same
 * order, as the bot's own `JoinChallengeFlow::confirm()`.
 *
 * There is no FormRequest because there is no input: the actor is the bearer
 * token's user and the body is empty.
 */
class JoinController extends Controller
{
    public function __construct(
        private readonly VerifyChannelMembership $gate,
        private readonly ResolveViewableChallenge $viewable,
        private readonly JoinChallenge $join,
        private readonly PurchaseEntitlement $purchases,
        private readonly CoinLedger $ledger,
    ) {}

    public function store(Request $request, string $challenge): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // 404 before the gate: a 403 for a stranger would confirm the challenge
        // exists, which is the id oracle the uniform 404 exists to close.
        $view = $this->viewable->handle($user, $challenge);

        if (! $this->gate->ensure($user)) {
            return response()->json([
                'reason' => 'channel_gate',
                // Null for a numeric channel id: there is genuinely no public
                // URL to join with, and the client says so rather than
                // offering a broken button.
                'join_url' => $this->gate->joinUrl($user),
            ], 403);
        }

        try {
            $participant = $this->join->handle($user, $view->challenge);
        } catch (ChallengeNotJoinableException $refused) {
            // Machine-readable, like the check-in endpoint's refusals: the
            // sentence lives in the SPA's catalogue in the user's locale.
            return response()->json(['reason' => $refused->reason->value], 422);
        } catch (NoEntitlementAvailableException $refused) {
            // 402 rather than 422: nothing about the request was wrong. The
            // reader lacks a thing money can buy, and the body is the quote.
            //
            // Both numbers are read at the tap through the shared readers, so
            // a price the admin changed a minute ago is the price quoted — and
            // the type comes off the exception rather than being assumed, so
            // this stays the price of the slot that actually failed.
            return response()->json([
                'reason' => 'no_join_slot',
                'price' => $this->purchases->priceOf($refused->type),
                'balance' => $this->ledger->balanceFor($user),
            ], 402);
        }

        // Read before anything touches the model: this is `JoinChallenge`'s own
        // "welcome aboard" versus "you are already in", and it is the whole of
        // what the two status codes below mean.
        $created = $participant->wasRecentlyCreated;

        // The resource reads the timeline off the challenge and the viewer's own
        // check-ins off the participation, and the action hands back a bare
        // model — so the contract is satisfied here rather than left to a lazy
        // load nothing would catch, since `preventLazyLoading()` is not enabled.
        // `loadMissing` because `show`'s lookup already brings both along on the
        // path a returning participant takes.
        $view->challenge->loadMissing('periods');
        $participant->setRelation('challenge', $view->challenge)->loadMissing('checkIns');

        // The response is the SPA's new state, not a patch to merge.
        return (new ChallengeResource($view->challenge, $participant, $user))
            ->response()
            ->setStatusCode($created ? 201 : 200);
    }
}
