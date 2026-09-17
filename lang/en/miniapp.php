<?php

return [

    'auth' => [
        'failed' => 'We could not verify your Telegram identity. Please close and reopen the app.',

        // The exchange never got a verdict at all — a dropped connection, or a
        // server that broke. Kept apart from `failed` above, which is the server
        // answering 401: this one says nothing about the identity, so the same
        // `initData` is worth sending again and there is something to retry.
        'unreachable' => 'We could not reach the server to sign you in.',

        // Shown when there is no identity to even try to verify: opened in a
        // browser, or on a page Telegram would not hand `initData` to. Nothing
        // is broken and there is nothing to retry — the app has to be opened
        // from the bot.
        'outside_telegram' => 'Open this app from Telegram — tap the button in your chat with the bot.',
        'retry' => 'Try again',

        // The third failure, and not the same one: the identity was verified
        // and a *later* request failed. Saying so is the whole point — the old
        // copy blamed the identity check the user had just watched succeed.
        'data_failed' => 'Your Telegram identity was verified, but your challenges could not be loaded.',
        'data_failed_status' => 'The server answered :status.',
    ],

    'coins' => ':count coins',

    'challenges' => [
        'title' => 'Your challenges',
        'empty' => 'You are not in any challenges yet. Join one from the bot and it will show up here.',
        'streak' => 'Streak',
        'best' => 'Best',
        'score' => 'Points',
        'freezes' => 'Freezes',
        'freeze_count' => ':used of :total used',
        'creator' => 'Creator',
        'period_label' => 'Period :index of :total',
        'check_in_due' => 'Check-in due',
        'all_set' => 'Checked in',
        'starts' => 'Starts :when',
        'ends' => 'Ends :when',
        'progress' => 'Progress',
        // The bar a viewer with no participation sees: it measures the shared
        // timeline's elapsed periods, not their own obligations, and calling
        // that "Progress" would read as a score of zero out of thirty.
        'timeline_progress' => 'Timeline',
        'history' => 'History',
        'not_started' => 'Has not started yet',
        'finished' => 'Finished',
        'via_bot' => 'Check in via the bot',
        // A creator who has not joined their own challenge yet — creating one
        // does not enrol them. Shown where a streak would be, so it is short;
        // `not_joined_body` is the sentence that explains the absence.
        'not_joined' => 'Not joined',
        'not_joined_body' => 'You are not taking part in this challenge yet, so there is no streak, score or freeze to show.',
    ],

    'check_in' => [
        'button' => 'Check in',
        'done' => 'Checked in!',
        'working' => 'Checking in…',
        'gate' => 'Join our channel to check in.',
        'join' => 'Join channel',
        'no_link' => 'Join the channel through the bot, then come back.',
        'value_prompt' => 'How many :unit?',
        'value_placeholder' => 'e.g. 30',
        'value_missing' => 'Enter the number you reached, then check in.',
        'value_invalid' => 'That is not a number. Enter what you reached, e.g. 30 or 12.5.',
        // Above the phrase a typed-proof challenge issued, the same line the
        // bot sends before it — this surface shows the phrase itself, so the
        // prompt asks for it back by name. The copy is written again here
        // because `bot` is a server-side group and never ships to the SPA.
        'phrase_prompt' => 'Type this phrase back to check in:',
        // The client-side blank check for a typed proof, mirroring the split
        // `value_missing` keeps with the server's `value_required`: say which
        // field is empty before the server has to.
        'phrase_missing' => 'Type the phrase above, then check in.',
        'scored' => ':value :unit — :score points!',
        'refused' => [
            'challenge_closed' => 'This challenge is no longer running.',
            'not_a_participant' => 'You are not an active participant in this challenge.',
            'no_open_period' => 'No period is open right now.',
            'already_settled' => 'This period is already settled.',
            'awaiting_review' => 'Your check-in is already waiting on a review.',
            'wrong_proof_type' => 'This challenge is not proven with a single tap.',
            'proof_missing' => 'Nothing was sent.',
            'phrase_mismatch' => 'That is not the phrase. Check it and send it again.',
            'value_required' => 'This challenge scores a number — enter it, then check in.',
        ],
        'unexpected' => 'Something went wrong. Please try again.',
    ],

    'join' => [
        'button' => 'Join this challenge',
        'working' => 'Joining…',
        'done' => 'You are in.',

        // The same gate as `check_in.gate`, said about a different act — the
        // reader arrived at a join button, not a check-in one. The button and
        // the no-link line under it stay the shared `check_in.*` pair: those
        // two are about the *channel*, and say the same thing either way.
        'gate' => 'Join our channel to take part in this challenge.',

        // Out of slots is not out of money, and the two sentences below are
        // what keeps that distinction: this one is always true, and `short` is
        // only added when the balance genuinely cannot cover the price.
        'no_slot' => 'You have used up your challenge-joining slots.',
        'price' => 'Another costs :price coins, and you have :balance.',
        'short' => 'You are :coins coins short — top up in the bot shop, then come back.',

        'unexpected' => 'Something went wrong. Please try again.',

        // Keys are `JoinRejection` values verbatim, so the screen can build
        // `miniapp.join.refused.<reason>` without a lookup table of its own.
        'refused' => [
            'challenge_closed' => 'This challenge is no longer running.',
            'timeline_exhausted' => 'This challenge has run out of periods, so there is nothing left to join.',
            'participation_ended' => 'You already took part in this one, and rejoining is up to its creator.',
        ],
    ],

];
