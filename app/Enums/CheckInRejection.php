<?php

namespace App\Enums;

/**
 * Why a check-in could not be submitted or reviewed.
 *
 * Carried on `CheckInRejectedException` so a caller can `match` on the cause
 * rather than parse a message. One enum for both directions — submitting and
 * reviewing — because every surface handles them the same way: catch, pick a
 * sentence, carry on.
 *
 * The cases exist to be *told apart*. "You already did this today", "that is not
 * your phrase" and "the creator is still looking at your photo" are three
 * different conversations, and a surface that cannot distinguish them ends up
 * saying something vague for all three.
 *
 * Deliberately **no** `label()`, matching `InviteRejection`: these do not name a
 * value in a list, they select which sentence a surface sends, and those
 * sentences are their own lang lines.
 */
enum CheckInRejection: string
{
    /**
     * The user is not in this challenge, or has left, been removed, or already
     * finished it.
     *
     * Also the answer when a client supplies a participant id belonging to
     * somebody else: the actor is re-resolved from the verified identity, so a
     * borrowed id finds nothing rather than somebody else's row.
     */
    case NotAParticipant = 'not_a_participant';

    /**
     * The challenge itself is not accepting check-ins: still scheduled, already
     * completed, or cancelled.
     */
    case ChallengeClosed = 'challenge_closed';

    /**
     * No period is open at this moment — the timeline has not started yet, or it
     * has run out.
     */
    case NoOpenPeriod = 'no_open_period';

    /**
     * The obligation is already settled: approved, missed, or frozen. Nothing
     * more can be submitted against it, and nothing more can be reviewed.
     */
    case AlreadySettled = 'already_settled';

    /**
     * A photo is already with the creator. Swapping it out mid-review would mean
     * the creator approves one image and the record keeps another.
     */
    case AwaitingReview = 'awaiting_review';

    /**
     * A typed phrase did not match, after normalisation. The most routine
     * rejection here, and not an error — it is the mechanic working.
     */
    case PhraseMismatch = 'phrase_mismatch';

    /**
     * Proof arrived in a shape this challenge does not use — a photo for a
     * one-tap challenge, or text where a photo is expected.
     */
    case WrongProofType = 'wrong_proof_type';

    /**
     * Proof is required and none arrived: an empty message, or an upload that
     * produced no file.
     */
    case ProofMissing = 'proof_missing';

    /**
     * The reviewer does not own this challenge. Platform admins are exempt;
     * nobody else reviews somebody else's challenge.
     */
    case NotTheReviewer = 'not_the_reviewer';

    /**
     * There is nothing to review: the row was never submitted for review, or it
     * has already been decided.
     */
    case NotAwaitingReview = 'not_awaiting_review';

    /**
     * The settlement cannot be undone: the row was never settled, or it has
     * already been reversed. An override can only flip a decision that is
     * currently in force.
     */
    case NotReversible = 'not_reversible';
}
