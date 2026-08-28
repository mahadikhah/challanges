<?php

namespace App\Actions\CheckIns;

use App\Actions\Ai\ApplyAiVerdict;
use App\Enums\CheckInStatus;
use App\Enums\ProofType;
use App\Exceptions\CheckInRejectedException;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\ChallengePeriod;
use App\Models\CheckIn;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The one place a participant submits proof, whatever surface they are on.
 *
 * The bot's tapped button, a phrase typed into the Mini App and a photo uploaded
 * through the admin panel all land here. There is one copy of "is this person
 * allowed to check in, into which period, and does this proof count" — the rule
 * cannot be right in the bot and wrong in the API.
 *
 * **Nothing is taken from the caller except the proof itself.** The signature
 * asks for the *verified actor* and a challenge, and resolves the participant row
 * and the open period server-side. A surface cannot pass a `participant_id`
 * because there is nowhere to put one, so a client cannot check in as somebody
 * else by editing a number — the class of bug this shape exists to make
 * unwritable.
 *
 * **A returned `CheckIn` always means the proof was accepted.** Everything else
 * throws `CheckInRejectedException` with a reason. That is deliberate: a wrong
 * phrase leaves the row `Pending` and a second tap leaves it `Approved`, and a
 * surface that had to tell those apart by reading a status would eventually
 * render "checked in!" for a typo. The reason enum makes each outcome a separate,
 * unmissable branch.
 *
 * The three entry points are named for what the participant *did*, and each one
 * asserts the challenge actually asks for that kind of proof. A photo handler
 * therefore cannot satisfy a `text_autogen` challenge even if it wants to.
 */
class SubmitCheckIn
{
    public function __construct(
        private readonly OpenCheckIn $open,
        private readonly IssueCheckInPhrase $phrases,
        private readonly SettleCheckIn $settle,
        private readonly ApplyAiVerdict $aiVerdict,
    ) {}

    /**
     * One tap. Auto-approved — the honesty model for `button` challenges is
     * social, not technical.
     *
     * @throws CheckInRejectedException
     */
    public function tap(User $actor, Challenge $challenge, ?CarbonInterface $now = null): CheckIn
    {
        $checkIn = $this->openSubmittable($actor, $challenge, ProofType::Button, $now);

        return $this->approved($checkIn);
    }

    /**
     * The participant's own phrase for this period, typed back.
     *
     * Compared with `matchesExpectedPhrase()`, which normalises both sides — so
     * capitals, stray whitespace, Persian digits and an Arabic yeh all pass, and
     * somebody else's phrase does not.
     *
     * @throws CheckInRejectedException
     */
    public function typePhrase(User $actor, Challenge $challenge, string $text, ?CarbonInterface $now = null): CheckIn
    {
        if (trim($text) === '') {
            throw CheckInRejectedException::proofMissing($challenge);
        }

        $checkIn = $this->openSubmittable($actor, $challenge, ProofType::TextAutogen, $now);

        // Issue on demand. A participant can reach this before any reminder went
        // out — they opened the Mini App on their own — and comparing against a
        // null phrase would refuse a correct answer nobody could have known.
        $this->phrases->handle($checkIn);

        if (! $checkIn->matchesExpectedPhrase($text)) {
            // The wrong text is deliberately not stored. `submitted_text` means
            // "the proof this row was settled on"; filling it with a typo would
            // put a submission time on a row that was never submitted.
            throw CheckInRejectedException::phraseMismatch($checkIn);
        }

        return DB::transaction(function () use ($checkIn, $text): CheckIn {
            $checkIn->update([
                'submitted_text' => $text,
                'submitted_at' => now(),
            ]);

            // In one transaction so that a settlement lost to the rollover takes
            // the recorded submission down with it, rather than leaving a `Missed`
            // row that claims the participant submitted on time.
            return $this->approved($checkIn);
        });
    }

    /**
     * A photo, stored and handed to the creator. **Not** auto-approved — the
     * creator decides, via `ReviewCheckIn`.
     *
     * `$path` is a stored path, not an upload: resolving a Telegram `file_id` or
     * moving an `UploadedFile` belongs to the surface, and keeping it out of here
     * is what lets the bot and the API share this method.
     *
     * @throws CheckInRejectedException
     */
    public function uploadPhoto(User $actor, Challenge $challenge, string $path, ?CarbonInterface $now = null): CheckIn
    {
        if (trim($path) === '') {
            throw CheckInRejectedException::proofMissing($challenge);
        }

        $checkIn = $this->openSubmittable($actor, $challenge, ProofType::ImageApproval, $now);

        $checkIn->update([
            'status' => CheckInStatus::Submitted,
            'proof_path' => $path,
            'submitted_at' => now(),
            // A resubmission after a rejection starts a fresh review. Leaving the
            // old reviewer stamped would make the new photo look already decided,
            // and it would drop out of the creator's queue unseen.
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);

        // An `approval_mode = ai` challenge reviews itself here: the router
        // asks the model and either settles through the ordinary path or
        // leaves the row in this same `Submitted` state for the manual
        // queue. Called after the write so the photo is stored before anyone
        // — model or human — is asked to look at it.
        $this->aiVerdict->handle($checkIn);

        return $checkIn->refresh();
    }

    /**
     * Settle as approved, insisting that it actually took.
     *
     * `SettleCheckIn::approve()` returns an already-settled row untouched by
     * design, which for a *submission* means the rollover closed the period
     * between the guard in `openSubmittable()` and this call. The participant was
     * a moment too late, and saying so beats handing back a `Missed` row that a
     * caller will read as success.
     *
     * @throws CheckInRejectedException
     */
    private function approved(CheckIn $checkIn): CheckIn
    {
        $settled = $this->settle->approve($checkIn);

        if ($settled->status !== CheckInStatus::Approved) {
            throw CheckInRejectedException::alreadySettled($settled);
        }

        return $settled;
    }

    /**
     * Resolve everything from the verified actor, and hand back a row that can
     * actually accept proof right now.
     *
     * @throws CheckInRejectedException
     */
    private function openSubmittable(User $actor, Challenge $challenge, ProofType $offered, ?CarbonInterface $now): CheckIn
    {
        if ($challenge->proof_type !== $offered) {
            throw CheckInRejectedException::wrongProofType($challenge, $offered->value);
        }

        if (! $challenge->status->acceptsCheckIns()) {
            throw CheckInRejectedException::challengeClosed($challenge);
        }

        $at = CarbonImmutable::instance($now ?? now());
        $participant = $this->participant($actor, $challenge);
        $period = $this->openPeriod($challenge, $at);

        if (! $participant->owesPeriod($period)) {
            // Reachable only for a participant who is no longer active: the open
            // period cannot predate a join that has already happened.
            throw CheckInRejectedException::notAParticipant($challenge);
        }

        $checkIn = $this->open->handle($participant, $period);

        if ($checkIn->status->isSettled()) {
            throw CheckInRejectedException::alreadySettled($checkIn);
        }

        if (! $checkIn->status->allowsSubmission()) {
            throw CheckInRejectedException::awaitingReview($checkIn);
        }

        return $checkIn;
    }

    /**
     * The actor's own participant row on this challenge.
     *
     * Resolved by `user_id` from the verified actor and scoped to the challenge,
     * which is the whole point: there is no path here for a client-supplied
     * participant id to reach a query.
     *
     * @throws CheckInRejectedException
     */
    private function participant(User $actor, Challenge $challenge): ChallengeParticipant
    {
        /** @var ChallengeParticipant|null $participant */
        $participant = $challenge->participants()->where('user_id', $actor->getKey())->first();

        if ($participant === null) {
            throw CheckInRejectedException::notAParticipant($challenge);
        }

        return $participant;
    }

    /**
     * The period this moment falls inside.
     *
     * Resolved rather than accepted, so a stale callback button from last week's
     * reminder cannot backdate a check-in into a period that has closed.
     *
     * @throws CheckInRejectedException
     */
    private function openPeriod(Challenge $challenge, CarbonImmutable $at): ChallengePeriod
    {
        /** @var ChallengePeriod|null $period */
        $period = $challenge->periods()->containing($at)->first();

        if ($period === null) {
            throw CheckInRejectedException::noOpenPeriod($challenge);
        }

        return $period;
    }
}
