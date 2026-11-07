<?php

namespace App\Services\Telegram;

use App\Actions\Telegram\VerifyChannelMembership;
use App\Models\Challenge;
use App\Models\ChallengeParticipant;
use App\Models\User;

/**
 * `/challenges` — everything this user has a stake in, and a way into it.
 *
 * The report behind this flow was not "the listing is wrong" but "there is no
 * listing". A user with three challenges had no single place that named them:
 * check-in reached them one challenge at a time, from a reminder, and a challenge
 * they had created and finished simply vanished from view.
 *
 * **Both halves, because they are different sets.** Participation and ownership
 * are tracked separately — `CreateChallenge` never writes a participant row for
 * the creator, so a creator who never joined is in no participation query at all,
 * and one who did join appears in both. Unioning the two, and the order the union
 * comes out in, is stated once in the repository, on
 * `User::challengeIdsNewestFirst()`, which is what this flow calls: the Mini App
 * lists the same two halves for the same user, and the two surfaces disagreeing
 * on the order of somebody's own challenges is a bug no test here would catch.
 *
 * **Nothing here decides what is owed.** That rule lives in `CheckInFlow` and is
 * reached through the button below, which is the same `cm:checkin` a typed
 * `/checkin` produces. Reimplementing "is this period open" here would be a
 * second copy of the rule that the Mini App and the admin panel do not have —
 * precisely the drift `CLAUDE.md`'s "every surface calls the same Actions" exists
 * to prevent, and the listing is the surface most tempted to do it.
 */
class MyChallengesFlow
{
    /**
     * How many challenges one listing names before trailing off.
     *
     * Ten titles with their status is already a wall on a phone, and Telegram
     * refuses a message past 4096 characters outright — a refusal the user would
     * read as the command being broken. The overflow is *counted* rather than
     * dropped silently: "…and 5 more" is honest, and a user who sees 10 of their
     * 15 challenges and no note would reasonably conclude the other five are gone.
     */
    private const int MAX_ROWS = 10;

    public function __construct(
        private readonly VerifyChannelMembership $gate,
        private readonly ChannelGatePrompt $gatePrompt,
        private readonly BotMessenger $messenger,
        private readonly BotButtons $buttons,
    ) {}

    /**
     * `/challenges`, or a tap on the button that stands in for it.
     */
    public function begin(User $user): void
    {
        // The listing names challenges and offers the check-in door, so it is a
        // privileged surface like any other rather than a harmless read.
        if (! $this->gate->ensure($user)) {
            $this->gatePrompt->send($user);

            return;
        }

        $rows = $this->rows($user);

        if ($rows === []) {
            // A door, not a diagnosis. This is where a brand-new user lands when
            // they go looking for something to do, and "you have nothing" with no
            // way to change it is the moment they close the bot.
            $this->buttons->send($user, 'bot.challenges.none', 'create');

            return;
        }

        $shown = array_slice($rows, 0, self::MAX_ROWS);

        $lines = array_map(fn (array $row): string => $this->rowLine($user, $row), $shown);

        if (($hidden = count($rows) - count($shown)) > 0) {
            $lines[] = $this->messenger->line($user, 'bot.challenges.more', ['count' => $hidden]);
        }

        $this->messenger->paragraphs(
            $user,
            $lines,
            $this->buttons->keyboard($user, 'checkin', 'create'),
        );
    }

    /**
     * Every challenge this user is in or owns, newest first.
     *
     * The union and its order are `User::challengeIdsNewestFirst()`'s, not this
     * flow's: the Mini App's challenge list asks the same question of the same
     * two halves, and the two surfaces must not name the same challenges in two
     * different orders. What is decided *here* is only what each row carries.
     *
     * @return list<array{challenge: Challenge, participation: ChallengeParticipant|null, created: bool}>
     */
    private function rows(User $user): array
    {
        $participations = $user->participations()
            ->with('challenge')
            ->get()
            ->keyBy('challenge_id');

        // Counted here rather than per row: a creator with ten challenges would
        // otherwise run one `count(*)` per challenge, and the number is only ever
        // read for the rows this user owns.
        $created = $user->createdChallenges()->withCount('participants')->get()->keyBy('id');

        $ids = $user->challengeIdsNewestFirst($participations->keys(), $created->keys());

        $rows = [];

        foreach ($ids as $id) {
            $participation = $participations->get($id);

            $rows[] = [
                // The created copy wins when a challenge is in both, because it is
                // the one carrying `participants_count`.
                'challenge' => $created->get($id) ?? $participation->challenge,
                'participation' => $participation,
                'created' => $created->has($id),
            ];
        }

        return $rows;
    }

    /**
     * One challenge, as one line: what it is, how you stand to it, and how it is
     * going.
     *
     * The three shapes get three lines rather than one line with the empty parts
     * dropped, because the sentences are genuinely different — "joined · streak 4"
     * is not "yours · 12 in it" — and a template with holes in it reads like a
     * template with holes in it in both languages.
     *
     * @param  array{challenge: Challenge, participation: ChallengeParticipant|null, created: bool}  $row
     */
    private function rowLine(User $user, array $row): string
    {
        $challenge = $row['challenge'];
        $participation = $row['participation'];

        $key = match (true) {
            $row['created'] && $participation !== null => 'bot.challenges.row_both',
            $row['created'] => 'bot.challenges.row_creator',
            default => 'bot.challenges.row_participant',
        };

        return $this->messenger->line($user, $key, [
            'title' => $challenge->title,
            'status' => $this->messenger->line($user, $challenge->status->translationKey()),
            // The creator is counted among the participants whenever they joined
            // their own challenge, and this line is *theirs* — so "3 in it" must
            // not mean "you and two others".
            'people' => max(0, (int) $challenge->participants_count - ($participation === null ? 0 : 1)),
            'streak' => $participation === null ? 0 : $participation->current_streak,
        ]);
    }
}
