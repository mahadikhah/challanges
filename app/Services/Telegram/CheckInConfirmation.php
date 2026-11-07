<?php

namespace App\Services\Telegram;

use App\Enums\CheckInStatus;
use App\Models\Challenge;
use App\Models\CheckIn;

/**
 * The one sentence a settled check-in is confirmed with.
 *
 * Three surfaces owe the participant the same words for the same outcome — the
 * check-in flow's tap, the timed session's completion and the verdict
 * notification a creator's review produces — and the sentence differs by
 * challenge shape: a binary challenge confirms a streak, a quantity challenge
 * confirms the number, the score it earned and the streak. One helper, so the
 * two shapes cannot drift apart between surfaces.
 *
 * Returns the line key and its replacements rather than sending anything: the
 * caller owns the messenger, this owns the words.
 */
class CheckInConfirmation
{
    /**
     * The confirmation line for a settled check-in, and what to fill it with.
     *
     * `$base` names the plain line a caller confirms with; a scored settlement
     * uses its `_scored` sibling, so a verdict notification and a tap can share
     * the words while keeping their own plain lines.
     *
     * The participant row is refreshed here because `SettleCheckIn` moves the
     * streak on a freshly locked row while the relation hanging off the
     * check-in instance may still hold the pre-settlement count — reporting
     * "streak: 0" on the day it became 1.
     *
     * @return array{0: string, 1: array<string, string|int|float>}
     */
    public function line(Challenge $challenge, CheckIn $checkIn, string $base = 'bot.checkin.confirmed'): array
    {
        $streak = $checkIn->participant->refresh()->current_streak;

        if (! $challenge->scoring_type->isQuantity() || $checkIn->score === null) {
            // A quantity row that settled without a score is a report that fell
            // short of the bar: the outcome deserves its own words, not a
            // "checked in" for a period that was missed or frozen. The same
            // line serves every surface — the outcome, not the surface, is
            // what the participant needs to hear.
            if ($challenge->scoring_type->isQuantity()
                && $checkIn->reported_value !== null
                && $checkIn->status !== CheckInStatus::Approved
                && $checkIn->status->isSettled()) {
                return ["bot.checkin.below_target_{$checkIn->status->value}", [
                    'title' => $challenge->title,
                    'value' => $this->plainNumber($checkIn->reported_value),
                    'target' => $this->plainNumber($challenge->target_value),
                    'unit' => (string) $challenge->unit_label,
                    'streak' => $streak,
                ]];
            }

            return [$base, [
                'title' => $challenge->title,
                'streak' => $streak,
            ]];
        }

        return ["{$base}_scored", [
            'title' => $challenge->title,
            'value' => $this->plainNumber($checkIn->reported_value),
            'unit' => (string) $challenge->unit_label,
            'score' => (int) $checkIn->score,
            'streak' => $streak,
        ]];
    }

    /**
     * A stored two-decimal value shown without its trailing zeros — "45", not
     * "45.00", because the participant typed "45" and recognises that.
     */
    private function plainNumber(?string $stored): string
    {
        if ($stored === null) {
            return '0';
        }

        $trimmed = rtrim(rtrim($stored, '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
