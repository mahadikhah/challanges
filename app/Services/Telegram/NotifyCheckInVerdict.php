<?php

namespace App\Services\Telegram;

use App\Models\CheckIn;

/**
 * Tell a participant what became of their photo.
 *
 * One service because both verdict surfaces — the bot's inline buttons and the
 * admin panel's review queue — owe the participant the same words for the same
 * outcome. The verdict itself is `ReviewCheckIn`'s to make; this is only the
 * delivery, so it says nothing the settled row does not already prove.
 *
 * A participant who hears nothing has no way of knowing a rejection means "send
 * another" rather than "you lost", and an approval is worth nothing until the
 * streak it moved is visible. The messages therefore quote the streak, fresh
 * from the row the settlement just wrote.
 */
class NotifyCheckInVerdict
{
    public function __construct(private readonly BotMessenger $messenger) {}

    public function approved(CheckIn $checkIn): void
    {
        $participant = $checkIn->participant;
        $user = $participant->user;

        $this->messenger->send($user, $this->messenger->line($user, 'bot.checkin.review_approved', [
            'title' => $participant->challenge->title,
            // Refreshed: the settlement just moved the streak on the locked row,
            // and the relation here may predate it.
            'streak' => $participant->refresh()->current_streak,
        ]));
    }

    public function rejected(CheckIn $checkIn): void
    {
        $participant = $checkIn->participant;
        $user = $participant->user;

        // The sentence names what to replace: "send another photo" would be
        // wrong advice for a voice note. The kind comes from the stored path,
        // the same source the review queue previews by.
        $kind = $checkIn->proofKind() ?? 'image';

        $this->messenger->send($user, $this->messenger->line($user, "bot.checkin.review_rejected_{$kind}", [
            'title' => $participant->challenge->title,
        ]));
    }
}
