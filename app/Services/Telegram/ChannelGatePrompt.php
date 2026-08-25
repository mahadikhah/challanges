<?php

namespace App\Services\Telegram;

use App\Actions\Telegram\VerifyChannelMembership;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Blocks a user at the channel gate, with a way through it.
 *
 * Extracted rather than repeated because every privileged command needs the same
 * refusal, and a refusal that differs per command is how one of them ends up
 * without a join button — a dead end the user cannot get out of. There is one
 * refusal, one button, one closing nudge.
 */
class ChannelGatePrompt
{
    public function __construct(
        private readonly VerifyChannelMembership $gate,
        private readonly BotMessenger $messenger,
    ) {}

    /**
     * Tell the user they must join first.
     *
     * @param  list<string|null>  $extraLines  sit between the refusal and the closing
     *                                         nudge; nulls are dropped
     */
    public function send(User $user, array $extraLines = []): void
    {
        $url = $this->gate->joinUrl();

        if ($url === null) {
            // A numeric `-100…` channel id has no public link, so there is no
            // button to offer and the user has to be invited another way. Worth a
            // warning: it is a configuration choice that quietly degrades the gate.
            Log::warning('The required channel has no public link, so no join button can be offered.', [
                'channel' => $this->gate->channel(),
            ]);
        }

        $this->messenger->paragraphs(
            $user,
            [
                $this->messenger->line(
                    $user,
                    $url === null ? 'bot.gate.blocked_without_link' : 'bot.gate.blocked',
                    ['channel' => $this->gate->channel()],
                ),
                ...$extraLines,
                $this->messenger->line($user, 'bot.gate.then_start_again'),
            ],
            $url === null ? null : [[[
                'text' => $this->messenger->line($user, 'bot.gate.join_button'),
                'url' => $url,
            ]]],
        );
    }
}
