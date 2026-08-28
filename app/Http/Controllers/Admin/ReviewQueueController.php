<?php

namespace App\Http\Controllers\Admin;

use App\Actions\CheckIns\ReviewCheckIn;
use App\Enums\CheckInStatus;
use App\Exceptions\CheckInRejectedException;
use App\Http\Controllers\Controller;
use App\Models\CheckIn;
use App\Models\User;
use App\Services\Telegram\NotifyCheckInVerdict;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The image-proof review queue.
 *
 * A photo that arrives for an `image_approval` challenge sits in `Submitted`
 * until its creator — or, when the creator has gone quiet, a platform admin —
 * decides it. This is the panel's window onto that queue, and it deliberately
 * contains no rules of its own: every verdict goes through `ReviewCheckIn`,
 * the same action the bot's inline buttons call, so a photo cannot be approved
 * here in a way the bot would refuse.
 *
 * The photos themselves are served from a gated route rather than linked from
 * the disk: proofs are private by default, and an admin URL is the only door
 * they should ever have.
 */
class ReviewQueueController extends Controller
{
    public function __construct(
        private readonly ReviewCheckIn $review,
        private readonly NotifyCheckInVerdict $notify,
    ) {}

    public function index(): InertiaResponse
    {
        $queue = CheckIn::query()
            ->where('status', CheckInStatus::Submitted)
            ->with(['participant.user', 'participant.challenge', 'period'])
            ->orderBy('submitted_at')
            ->limit(100)
            ->get()
            ->map(fn (CheckIn $checkIn): array => [
                'id' => $checkIn->getKey(),
                'challenge' => $checkIn->participant->challenge->title,
                'participant' => $this->participantName($checkIn),
                'period' => $checkIn->period->index + 1,
                'total_periods' => $checkIn->participant->challenge->total_periods,
                'submitted_at' => optional($checkIn->submitted_at)->toIso8601String(),
                'proof_url' => route('admin.reviews.proof', $checkIn->getKey()),
            ])
            ->all();

        return Inertia::render('Admin/Reviews', ['reviews' => $queue]);
    }

    /**
     * One stored proof, streamed to an authenticated admin only.
     */
    public function proof(CheckIn $checkIn): BinaryFileResponse|Response
    {
        $path = $checkIn->proof_path;

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return response()->file(Storage::disk('local')->path($path));
    }

    public function approve(Request $request, CheckIn $checkIn): RedirectResponse
    {
        return $this->verdict($request, $checkIn, approved: true);
    }

    public function reject(Request $request, CheckIn $checkIn): RedirectResponse
    {
        return $this->verdict($request, $checkIn, approved: false);
    }

    /**
     * One verdict, one flash, one redirect — whatever the action says.
     *
     * A refusal is a normal outcome here: the queue is a snapshot, and the
     * rollover may have settled a row between the page load and the click. The
     * admin gets the reason as a toast, the same way the bot's creator gets it
     * as a reply.
     */
    private function verdict(Request $request, CheckIn $checkIn, bool $approved): RedirectResponse
    {
        $admin = $this->theAdmin($request);

        try {
            $settled = $approved
                ? $this->review->approve($admin, $checkIn)
                : $this->review->reject($admin, $checkIn);

            $approved ? $this->notify->approved($settled) : $this->notify->rejected($settled);

            Inertia::flash('toast', [
                'type' => 'success',
                'message' => __('admin.reviews.'.($approved ? 'approved' : 'rejected')),
            ]);
        } catch (CheckInRejectedException $refused) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __("admin.reviews.refused.{$refused->reason->value}"),
            ]);
        }

        return back();
    }

    /**
     * `auth` + `EnsureUserIsAdmin` have both run by now; this is the typed
     * narrowing, not a second permission check.
     */
    private function theAdmin(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * What the queue calls a participant: their Telegram first name when they
     * have one, falling back to whatever name the row carries.
     */
    private function participantName(CheckIn $checkIn): string
    {
        $user = $checkIn->participant->user;

        return $user->first_name ?? $user->name;
    }
}
