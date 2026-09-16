<?php

return [

    'auth' => [
        'failed' => 'We could not verify your Telegram identity. Please close and reopen the app.',

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
        'history' => 'History',
        'not_started' => 'Has not started yet',
        'finished' => 'Finished',
        'via_bot' => 'Check in via the bot',
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

];
