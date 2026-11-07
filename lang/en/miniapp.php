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

    /*
    | The create form. One group per question rather than one per field, because
    | the screen asks them that way — the heading of each step is the prompt.
    |
    | The picker *options* are not here: every one of them is an enum label the
    | API serves as `{value,label}` (`miniapp.create` only ever writes the
    | question). Nor are the numbers: the character limits, the media ceilings
    | and the slot price all arrive in the options payload, because an admin who
    | changes one expects the form to change with it.
    */
    'create' => [
        // The list header's own button, and the heading the form opens on.
        'button' => 'New challenge',
        'heading' => 'New challenge',
        'step' => 'Step :index of :total',
        'next' => 'Next',
        'back' => 'Back',
        'submit' => 'Create challenge',
        'working' => 'Creating…',
        'retry' => 'Try again',

        // The options are a request of their own, and it can fail on its own.
        // The form cannot be drawn at all in that state — every bound it
        // enforces comes from that payload — so this is a wall, not a hint.
        'loading' => 'Loading your options…',
        'load_failed' => 'We could not load what this form needs.',

        'title' => 'What is this challenge called?',
        'title_placeholder' => '30 days of pushups',
        'title_too_short' => 'Give it at least :count characters.',

        'description' => 'Describe it for the people you invite.',
        'description_placeholder' => 'What counts, and what does not',
        'description_optional' => 'Optional.',

        'period_type' => 'How often does it have to be done?',
        'custom_period_days' => 'How many days is one period?',
        'custom_period_days_hint' => 'Enter between 1 and :max days.',

        // Asked before the start date, and for the reason the bot's wizard asks
        // it there too: "starting on the 3rd" is not an instant until the zone
        // is known.
        'timezone' => 'Which timezone are you starting in?',
        'timezone_hint' => 'Every period begins and ends in this timezone. The start date below is read in it.',

        'starts_at' => 'Which day does it start?',
        'starts_at_hint' => 'The first period opens that morning, in the timezone above.',
        'starts_at_past' => 'Pick today or a later day.',
        'starts_at_missing' => 'Pick the day this challenge starts.',

        'total_periods' => 'How many periods does it run for?',
        'total_periods_hint' => 'One period is one check-in. Between 1 and :max.',

        'scoring_type' => 'How is each check-in scored?',
        'target_value' => 'How much is one period?',
        'unit_label' => 'Measured in what?',
        'unit_label_placeholder' => 'pushups',
        'base_points' => 'How many points is a full period worth?',
        'partial' => 'Count a partial period as done',

        // The one line that has to be said before the choice is made: three of
        // these five are settled by a person, and the road to them is the bot.
        'proof_type' => 'How do participants prove it?',
        'proof_type_hint' => 'A photo, voice or video proof is sent through the bot, and you approve or reject it here.',

        'media_caps' => 'What are the recording limits?',
        'media_seconds' => 'Longest recording, in seconds',
        'media_size_kb' => 'Largest file, in kilobytes',
        'media_hint' => 'Up to :seconds seconds and :size KB — the platform maximums.',

        'visibility' => 'Who can see this challenge?',
        'visibility_hint' => 'A public challenge is posted to the announcement channel too.',

        // The read-back. Every row is a fact the server will act on, so it
        // repeats the answers rather than summarising them.
        'summary' => 'Ready to create',
        // The one row that is not the creator's own answer: `flow_types` says
        // what this build can create, and the password to Task 7 is that it
        // extends that list rather than reshaping this screen.
        'summary_flow' => 'Flow: :flow',
        'summary_starts' => 'Starts :date',
        'summary_timezone' => 'Time zone: :zone',
        'summary_periods' => ':count periods',
        'summary_freezes' => ':count freezes to start',
        'summary_custom_days' => 'One period every :count days',
        'summary_target' => 'A full period is :value :unit',
        'summary_points' => 'Worth :points points',
        'summary_partial' => 'A partial period counts as done',
        'summary_media' => 'Recording up to :seconds seconds, :size KB',
        // The cost, said before the tap rather than after it. `no_slot` is the
        // same arithmetic the refusal quotes, read from the options payload.
        'summary_slot' => 'This uses one of your create-slots. You have :slots.',
        'summary_no_slot' => 'You have no create-slots left, so creating this will be refused.',

        // The access gate, said about creating — the same gate as the join and
        // check-in ones, and the button and no-link line under it stay the
        // shared `check_in.*` pair.
        'gate' => 'Join our channel to create a challenge.',

        // Out of slots, but the server answered with the numbers, so the banner
        // quotes a shortfall instead of stopping at "no" — the same three lines
        // `miniapp.join` keeps, said about this act.
        'no_slot' => 'You have used up your challenge-creating slots.',
        'price' => 'Another costs :price coins, and you have :balance.',
        'short' => 'You are :coins coins short — top up in the bot shop, then come back.',

        // The two start-date refusals the server names. Both are backstops:
        // the screen checks the date itself before it submits, so seeing one of
        // these means the two disagreed.
        'invalid_start_date' => 'That is not a date we can read. Pick it from the date field.',
        'past_start_date' => 'Pick today or a later day.',

        'unexpected' => 'Something went wrong. Please try again.',

        // The client-side checks, each the friendly half of one the server
        // enforces anyway.
        'number_required' => 'Enter a number.',
        'number_range' => 'Enter a number between :min and :max.',
        'number_positive' => 'Enter a number greater than zero.',
        'unit_required' => 'Name the unit this is counted in.',
    ],

];
