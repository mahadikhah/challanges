<?php

namespace App\Exceptions;

use App\Enums\CheckInRejection;
use App\Models\Challenge;
use App\Models\CheckIn;
use RuntimeException;

/**
 * A check-in was offered, or a review attempted, and refused.
 *
 * One class rather than ten, because every caller handles these together — the
 * bot catches it and picks a reply, an HTTP surface catches it and picks a status
 * code — and the `reason` is what selects the reply.
 *
 * **Most of these are not errors.** A mistyped phrase, a second tap on a day
 * already done, a photo still with the creator: all routine, all expected to be
 * caught and answered. Callers should not let one reach the webhook or a 500
 * page. The two that *are* refusals worth logging are `NotTheReviewer`, which is
 * an authorization failure, and `WrongProofType`, which means a surface sent the
 * wrong kind of proof and is a bug in that surface.
 *
 * The `checkIn` is attached when a row exists, so a caller can render the
 * participant's actual state — "you were marked as missed" rather than a bare
 * refusal.
 */
class CheckInRejectedException extends RuntimeException
{
    private function __construct(
        public readonly CheckInRejection $reason,
        public readonly ?CheckIn $checkIn,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notAParticipant(Challenge $challenge): self
    {
        return new self(
            CheckInRejection::NotAParticipant,
            null,
            "No active participant on challenge {$challenge->id} for this user.",
        );
    }

    public static function challengeClosed(Challenge $challenge): self
    {
        return new self(
            CheckInRejection::ChallengeClosed,
            null,
            "Challenge {$challenge->id} is {$challenge->status->value} and is not accepting check-ins.",
        );
    }

    public static function noOpenPeriod(Challenge $challenge): self
    {
        return new self(
            CheckInRejection::NoOpenPeriod,
            null,
            "Challenge {$challenge->id} has no period open at this moment.",
        );
    }

    public static function alreadySettled(CheckIn $checkIn): self
    {
        return new self(
            CheckInRejection::AlreadySettled,
            $checkIn,
            "Check-in {$checkIn->id} is already settled as {$checkIn->status->value}.",
        );
    }

    public static function awaitingReview(CheckIn $checkIn): self
    {
        return new self(
            CheckInRejection::AwaitingReview,
            $checkIn,
            "Check-in {$checkIn->id} is already submitted and waiting on the creator.",
        );
    }

    public static function phraseMismatch(CheckIn $checkIn): self
    {
        return new self(
            CheckInRejection::PhraseMismatch,
            $checkIn,
            "The text submitted for check-in {$checkIn->id} is not its expected phrase.",
        );
    }

    /**
     * The surface offered proof of a kind this challenge does not use.
     */
    public static function wrongProofType(Challenge $challenge, string $offered): self
    {
        return new self(
            CheckInRejection::WrongProofType,
            null,
            "Challenge {$challenge->id} is proven by {$challenge->proof_type->value}, not {$offered}.",
        );
    }

    public static function proofMissing(Challenge $challenge): self
    {
        return new self(
            CheckInRejection::ProofMissing,
            null,
            "Challenge {$challenge->id} requires {$challenge->proof_type->value} proof and none was supplied.",
        );
    }

    /**
     * A recording outran the challenge's own duration cap.
     *
     * The counts travel on the exception so a surface can render "this
     * challenge accepts up to N seconds" in the participant's language,
     * exactly as `SessionRejectedException` carries its seconds.
     */
    public static function mediaTooLong(Challenge $challenge, int $seconds): self
    {
        return new self(
            CheckInRejection::MediaTooLong,
            null,
            "Challenge {$challenge->id} accepts proof media of at most "
            ."{$challenge->proof_media_max_seconds} seconds; {$seconds} arrived.",
        );
    }

    public static function mediaTooLarge(Challenge $challenge, int $sizeKb): self
    {
        return new self(
            CheckInRejection::MediaTooLarge,
            null,
            "Challenge {$challenge->id} accepts proof media of at most "
            ."{$challenge->proof_media_max_size_kb} KB; {$sizeKb} KB arrived.",
        );
    }

    /**
     * A quantity challenge takes a number, and none arrived.
     */
    public static function valueRequired(Challenge $challenge): self
    {
        return new self(
            CheckInRejection::ValueRequired,
            null,
            "Challenge {$challenge->id} scores a reported quantity and none was supplied.",
        );
    }

    /**
     * A quantity check-in reached its verdict with no reported value on the
     * row — the participant was asked but never answered.
     */
    public static function valueMissing(CheckIn $checkIn): self
    {
        return new self(
            CheckInRejection::ValueMissing,
            $checkIn,
            "Check-in {$checkIn->id} has no reported value to score.",
        );
    }

    public static function notTheReviewer(CheckIn $checkIn): self
    {
        return new self(
            CheckInRejection::NotTheReviewer,
            $checkIn,
            "Check-in {$checkIn->id} may only be reviewed by its challenge creator.",
        );
    }

    public static function notAwaitingReview(CheckIn $checkIn): self
    {
        return new self(
            CheckInRejection::NotAwaitingReview,
            $checkIn,
            "Check-in {$checkIn->id} is {$checkIn->status->value} and is not awaiting review.",
        );
    }

    public static function notReversible(CheckIn $checkIn): self
    {
        return new self(
            CheckInRejection::NotReversible,
            $checkIn,
            "Check-in {$checkIn->id} is {$checkIn->status->value} and cannot be reversed.",
        );
    }
}
