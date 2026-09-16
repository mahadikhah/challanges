<?php

namespace App\Services\Telegram;

use App\Actions\Challenges\JoinChallenge;
use App\Actions\Telegram\VerifyChannelMembership;
use App\Enums\EntitlementType;
use App\Exceptions\ChallengeNotJoinableException;
use App\Exceptions\NoEntitlementAvailableException;
use App\Models\Challenge;
use App\Models\User;
use App\Services\Settings;
use App\Services\Telegram\Callbacks\JoinCallback;

/**
 * The join conversation: a preview, then a tap.
 *
 * Two entry points arrive here — the announcement channel's button and an
 * invite-only deep link, both funnelled through `/start` — and one action,
 * `JoinChallenge`, does the actual work, so the Mini App and the admin panel
 * cannot disagree with the bot about what joining costs.
 *
 * **A preview, deliberately, rather than joining on arrival.** Joining spends a
 * slot, and a deep link is opened by a tap; a link that joined on open would
 * spend somebody's one free join by accident. So the arrival shows what the
 * challenge is and asks, and the spend happens on an explicit second tap.
 *
 * Not a `Wizards/` class: there is no state to hold between the two steps. The
 * challenge is addressed by its join token in the button's `callback_data`, and
 * the button is re-derivable from the challenge at any time — nothing about
 * "what was asked" lives outside the message itself.
 *
 * **The gate is checked at the tap, not at the preview.** The preview spends
 * nothing, so an ungated user may read what they would be joining — that is the
 * advertisement doing its job. The tap is the privileged act, and it re-verifies
 * membership rather than trusting whatever `/start` established.
 */
class JoinChallengeFlow
{
    public function __construct(
        private readonly JoinChallenge $join,
        private readonly VerifyChannelMembership $gate,
        private readonly ChannelGatePrompt $gatePrompt,
        private readonly BotMessenger $messenger,
        private readonly Settings $settings,
        private readonly CheckInInstruction $instructions,
        private readonly PeriodUnit $units,
    ) {}

    /**
     * Show what they would be joining, with the button that joins.
     */
    public function preview(User $user, Challenge $challenge): void
    {
        $participant = $challenge->participants()->where('user_id', $user->getKey())->first();

        if ($participant !== null && ! $participant->status->isTerminal()) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.join.already_in', [
                'title' => $challenge->title,
            ]));

            return;
        }

        if (! $challenge->status->acceptsJoins()) {
            $this->messenger->send($user, $this->messenger->line(
                $user,
                'bot.join.refused.challenge_closed',
                ['title' => $challenge->title],
            ));

            return;
        }

        $this->messenger->paragraphs($user, [
            $this->messenger->line($user, 'bot.join.preview_headline', ['title' => $challenge->title]),
            $challenge->description,
            $this->messenger->line($user, 'bot.join.preview_details', [
                'period' => $this->messenger->line($user, $challenge->period_type->translationKey()),
                // The challenge's own unit rather than a period count: "Daily ·
                // 10 days · Photo" is what a daily challenge of ten periods is,
                // and a custom 3-day one of six periods is "18 days".
                'length' => $this->units->length($user, $challenge),
                'proof' => $this->messenger->line($user, $challenge->proof_type->translationKey()),
            ]),
            $this->messenger->line($user, 'bot.join.preview_freezes', [
                'freezes' => $challenge->default_freezes,
            ]),
        ], [
            [
                [
                    'text' => $this->messenger->line($user, 'bot.join.join_button'),
                    'callback_data' => BotCallback::encode(JoinCallback::ACTION, $challenge->join_token),
                ],
            ],
        ]);
    }

    /**
     * Take the tap, or explain why not.
     */
    public function confirm(User $user, string $token): void
    {
        $challenge = Challenge::query()->where('join_token', $token)->first();

        if ($challenge === null) {
            $this->messenger->send($user, $this->messenger->line($user, 'bot.join.not_found'));

            return;
        }

        // The privileged act: re-verified, not inherited from `/start`. The
        // button stays live in their chat, so joining the channel and tapping
        // again picks up right where this left off.
        if (! $this->gate->ensure($user)) {
            $this->gatePrompt->send($user);

            return;
        }

        try {
            $participant = $this->join->handle($user, $challenge);
        } catch (ChallengeNotJoinableException $refused) {
            // Only the timeline refusal reads `:length`; the others are passed it
            // by the same call rather than three call sites each knowing which
            // reasons happen to interpolate what.
            $this->messenger->send($user, $this->messenger->line(
                $user,
                "bot.join.refused.{$refused->reason->value}",
                [
                    'title' => $refused->challenge->title,
                    'length' => $this->units->length($user, $refused->challenge),
                ],
            ));

            return;
        } catch (NoEntitlementAvailableException) {
            // They had a slot when they tapped the link and spent it elsewhere
            // since — the same race the create wizard answers.
            $this->refuseForNoSlot($user);

            return;
        }

        // The mechanic, at the moment they join, in the same message. "Check in
        // every period" is a promise they have just accepted and no statement of
        // what accepting it means; this is the one moment they are certainly
        // reading, and it costs no extra message.
        $this->messenger->send($user, $this->messenger->line($user, $participant->wasRecentlyCreated ? 'bot.join.joined' : 'bot.join.already_in', [
            'title' => $challenge->title,
            'span' => $this->units->span($user, $challenge),
            'how' => $this->instructions->lineFor($user, $challenge),
        ]));
    }

    /**
     * Say that a join-slot is needed, and what one costs.
     *
     * Quoting the price at the moment of refusal, because it is a `Setting`: a
     * cached number would go stale the moment an admin changed it.
     */
    private function refuseForNoSlot(User $user): void
    {
        $this->messenger->paragraphs($user, [
            $this->messenger->line($user, 'bot.join.no_slot'),
            $this->messenger->line($user, 'bot.join.slot_price', [
                'coins' => $this->settings->integer(EntitlementType::JoinSlot->priceSetting()),
            ]),
        ]);
    }
}
