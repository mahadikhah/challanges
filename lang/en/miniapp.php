<?php

return [

    'auth' => [
        'failed' => 'We could not verify your Telegram identity. Please close and reopen the app.',
        'retry' => 'Try again',
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
