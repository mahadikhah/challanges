<?php

namespace App\Services\Telegram\Callbacks;

use App\Actions\CheckIns\ReviewCheckIn;
use App\Actions\Telegram\VerifyChannelMembership;
use App\Exceptions\CheckInRejectedException;
use App\Models\CheckIn;
use App\Models\User;
use App\Services\Telegram\BotButtons;
use App\Services\Telegram\BotCallback;
use App\Services\Telegram\BotMessenger;
use App\Services\Telegram\ChannelGatePrompt;
use App\Services\Telegram\HandlesCallback;
use App\Services\Telegram\NotifyCheckInVerdict;

/**
 * The creator's verdict on a submitted photo.
 *
 * The button carries a check-in id, which is safe only because of what happens
 * next: `ReviewCheckIn` re-derives ownership from the row — the id names its own
 * challenge, the challenge names its own creator, and the creator has to be the
 * actor. A tap from anybody else is refused with `not_the_reviewer`, exactly as a
 * crafted `callback_data` deserves.
 *
 * The participant hears the verdict too. A photo the creator approved is worth
 * nothing to the participant until the streak moves and they can see it did;
 * a rejected one is worth nothing at all until they are told to send another.
 */
class ReviewCheckInCallback implements HandlesCallback
{
    /**
     * The action word on a review button, and its two verdicts.
     */
    public const ACTION = 'rv';

    public const APPROVE = 'a';

    public const REJECT = 'r';

    public function __construct(
        private readonly ReviewCheckIn $review,
        private readonly VerifyChannelMembership $gate,
        private readonly ChannelGatePrompt $gatePrompt,
        private readonly BotMessenger $messenger,
        private readonly BotButtons $buttons,
        private readonly NotifyCheckInVerdict $notify,
    ) {}

    public function handle(User $user, BotCallback $callback): void
    {
        $id = $callback->argument(0);
        $verdict = $callback->argument(1);

        // Anything that is not exactly one of the two verdicts is a payload from
        // a format we no longer speak — answered, not guessed at.
        if ($id === null || ! ctype_digit($id) || ($verdict !== self::APPROVE && $verdict !== self::REJECT)) {
            $this->buttons->stale($user);

            return;
        }

        // A review is a privileged act: the gate is re-verified at the tap, not
        // inherited from whenever the notification was sent.
        if (! $this->gate->ensure($user)) {
            $this->gatePrompt->send($user);

            return;
        }

        /** @var CheckIn|null $checkIn */
        $checkIn = CheckIn::query()->find((int) $id);

        if ($checkIn === null) {
            $this->buttons->stale($user);

            return;
        }

        try {
            $settled = $verdict === self::REJECT
                ? $this->review->reject($user, $checkIn)
                : $this->review->approve($user, $checkIn);
        } catch (CheckInRejectedException $refused) {
            $this->messenger->send($user, $this->messenger->line(
                $user,
                "bot.checkin.review_refused.{$refused->reason->value}",
            ));

            return;
        }

        $this->messenger->send($user, $this->messenger->line($user, $verdict === self::REJECT
            ? 'bot.checkin.review_rejected_ack'
            : 'bot.checkin.review_approved_ack'));

        // The participant hears the verdict through the same delivery both
        // surfaces share — the panel's review queue sends these too.
        $verdict === self::REJECT
            ? $this->notify->rejected($settled)
            : $this->notify->approved($settled);
    }
}
