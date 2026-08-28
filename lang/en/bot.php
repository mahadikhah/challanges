<?php

/*
| Everything the bot says.
|
| Deliberately absent from `localization.client_groups`: this group is only ever
| read server-side, through `BotMessenger::line()` with an explicit locale, so
| shipping it to a browser bundle would be dead weight in both frontends.
*/

return [

    'start' => [
        'welcome' => 'Welcome to :app, :name! Set yourself a challenge, bring your friends along, and keep the streak alive.',
        'welcome_back' => 'Welcome back, :name.',
        'next_steps' => 'Send /create to set up a challenge. /cancel gets you out of anything half-finished.',
    ],

    'gate' => [
        'blocked' => 'Before you can use the bot, please join :channel.',
        'blocked_without_link' => 'Before you can use the bot, please join our announcement channel. Ask an admin for the link.',
        'then_start_again' => 'Once you have joined, send /start again.',
        'join_button' => 'Join the channel',
    ],

    'invite' => [
        'credited' => ':name invited you, and they have been rewarded for it.',
        'claimed' => 'You came in through :name’s invite.',

        'refused' => [
            'not_found' => 'That invite link is no longer valid, but you are in all the same.',
            'self_invite' => 'That was your own invite link, so nobody was credited for it.',
            'already_claimed' => 'That invite link has already been used by somebody else.',
            'invitee_already_attributed' => 'You already arrived through an invite, so this one was not counted.',
        ],
    ],

    /*
    | The join conversation. `refused` lines are addressed by the value of a
    | `JoinRejection` case, exactly as the invite ones are by `InviteRejection`.
    */
    'join' => [
        'not_found' => 'That join link is no longer valid.',

        'preview_headline' => 'Challenge: :title',
        'preview_details' => ':period · :periods periods · :proof',
        'preview_freezes' => 'Freezes each: :freezes',
        'join_button' => 'Join',

        'joined' => 'You’re in “:title”. Check in every period to keep the streak alive.',
        'already_in' => 'You are already in “:title”.',

        'no_slot' => 'You have used up your challenge-joining slots.',
        'slot_price' => 'Joining another costs :coins coins.',

        'refused' => [
            'challenge_closed' => '“:title” is no longer open to join.',
            'timeline_exhausted' => '“:title” has run out of periods, so there is nothing left to join.',
            'participation_ended' => 'You already took part in this one, and rejoining is up to its creator.',
        ],
    ],

    'cancel' => [
        'nothing_open' => 'There was nothing to cancel. Send /create to start a challenge.',
    ],

    /*
    | The check-in conversation. `refused` lines are addressed by the value of a
    | `CheckInRejection` case, and `review_refused` by the subset `ReviewCheckIn`
    | itself can throw — the same convention the invite and join groups use.
    */
    'checkin' => [
        'none' => 'You are not in any challenges yet.',
        'nothing_due' => 'Nothing is due from you right now. Check back when the next period opens.',
        'todo' => '“:title” — period :index of :total is open.',
        'done' => '“:title” — already checked in. Streak: :streak',
        'awaiting_review' => '“:title” — your photo is with the creator, waiting on a verdict.',
        'button' => 'Check in: :title',
        'not_found' => 'That check-in button no longer belongs to a challenge.',

        'phrase_prompt' => 'Type this phrase back to check in for “:title”:',
        'phrase_error' => 'That is not the phrase. Check it and send it again.',
        'phrase_expected' => 'Send the phrase as a text message.',

        'photo_prompt' => 'Send a photo to check in for “:title”. The creator will take a look.',
        'photo_expected' => 'Send the proof as a photo.',
        'photo_sent' => 'Your photo for “:title” is in. You will hear back once the creator has reviewed it.',
        'photo_error' => 'That photo could not be received. Please send it again.',

        'confirmed' => 'Checked in for “:title”. Streak: :streak',

        'review_prompt' => ':name has sent a photo for “:title”.',
        'approve_button' => 'Approve',
        'reject_button' => 'Reject',
        'review_approved_ack' => 'Approved.',
        'review_rejected_ack' => 'Rejected.',
        'review_approved' => 'Your check-in for “:title” was approved. Streak: :streak',
        'review_rejected' => 'Your photo for “:title” was turned down. Send another before the period ends.',

        'refused' => [
            'challenge_closed' => '“:title” is no longer running.',
            'not_a_participant' => 'You are not an active participant in “:title”.',
            'no_open_period' => '“:title” has no period open right now.',
            'already_settled' => 'That period is already settled.',
            'awaiting_review' => 'Your check-in for “:title” is already waiting on the creator.',
            'wrong_proof_type' => '“:title” is not proven that way.',
            'proof_missing' => 'Nothing was sent.',
        ],

        'review_refused' => [
            'not_the_reviewer' => 'Only the creator of that challenge can review it.',
            'already_settled' => 'That period has already closed, so the verdict can no longer change.',
            'not_awaiting_review' => 'There is no photo waiting on you for that one.',
        ],
    ],

    /*
    | A challenge's own lifecycle, told to its participants by the bot. The
    | admin panel's cancellation fans these out as staggered queued sends.
    */
    'challenge' => [
        'cancelled' => '“:title” has been cancelled, so there are no more check-ins to send. Your streak so far stands.',
    ],

    /*
    | The create-challenge wizard.
    |
    | The per-step keys are addressed as `bot.wizard.<conversation state>.prompt`,
    | `.error` (the answer could not be read) and `.expected` (the answer arrived in
    | the wrong form — typing at a step that wants a button, or a photo at a step
    | that wants words). The state value is interpolated by the wizard, so a new
    | step needs its three lines here and no other wiring.
    */
    'wizard' => [
        'opening' => 'Let’s set up a challenge. Ten quick questions — send /cancel at any point to stop.',
        'restarted' => 'Starting a new challenge from scratch. The previous draft has been dropped.',
        'cancelled' => 'Dropped. Nothing was created.',
        'stale_step' => 'That button belongs to an earlier question. Here is where we are now.',
        'incomplete' => 'That draft is missing some answers, so it has been dropped. Send /create to start again.',
        'error' => 'Something went wrong creating that challenge, and it has not been saved. Please try /create again.',

        'no_slot' => 'You have used up your challenge-creation slots.',
        'slot_price' => 'Another one costs :coins coins.',

        'skip_button' => 'Skip',
        'today_button' => 'Today',
        'tomorrow_button' => 'Tomorrow',
        'create_button' => 'Create it',
        'cancel_button' => 'Cancel',
        'no_description' => '(none)',

        'summary' => "Here is your challenge:\n\nTitle: :title\nDescription: :description\nPeriod: :period\nCustom length: :custom_days days\nStarts: :start (:timezone)\nPeriods: :periods\nProof: :proof\nVisibility: :visibility\nFreezes each: :freezes",

        'created' => '“:title” is ready.',
        'created_timeline' => ':periods periods, starting :start in :timezone.',
        'created_public' => 'It is public, so it is being posted to the announcement channel for others to join.',
        'created_private' => 'It is invite-only, so nobody can join without a link from you.',

        'awaiting_challenge_title' => [
            'prompt' => 'What is the challenge called? Between 3 and :title_max characters.',
            'error' => 'That title needs to be between 3 and :title_max characters.',
            'expected' => 'Please send the title as a text message.',
        ],

        'awaiting_challenge_description' => [
            'prompt' => 'Add a description, or tap Skip. Up to :description_max characters.',
            'error' => 'That description is longer than :description_max characters.',
            'expected' => 'Send a description as text, or tap Skip.',
        ],

        'awaiting_period_type' => [
            'prompt' => 'How often does everyone check in?',
            'error' => 'Please pick one of the periods offered.',
            'expected' => 'Tap one of the buttons to pick a period.',
        ],

        'awaiting_custom_period_days' => [
            'prompt' => 'How many days is one period? Up to :custom_period_days_max.',
            'error' => 'Send a whole number of days between 1 and :custom_period_days_max.',
            'expected' => 'Send the number of days as a text message.',
        ],

        'awaiting_timezone' => [
            'prompt' => 'Which timezone should the periods follow? Everyone in the challenge shares it.',
            'error' => 'Please pick one of the timezones offered.',
            'expected' => 'Tap one of the buttons to pick a timezone.',
        ],

        'awaiting_start_date' => [
            'prompt' => 'When does it start? Send a date as YYYY-MM-DD, read in :timezone, or use a button.',
            'error' => 'That is not a date I can use. Send it as YYYY-MM-DD, today or later.',
            'expected' => 'Send the start date as text, or tap Today or Tomorrow.',
        ],

        'awaiting_total_periods' => [
            'prompt' => 'How many periods long is the challenge? Up to :total_periods_max.',
            'error' => 'Send a whole number between 1 and :total_periods_max.',
            'expected' => 'Send the number of periods as a text message.',
        ],

        'awaiting_proof_type' => [
            'prompt' => 'How does somebody prove they did it?',
            'error' => 'Please pick one of the proof types offered.',
            'expected' => 'Tap one of the buttons to pick a proof type.',
        ],

        'awaiting_visibility' => [
            'prompt' => 'Who can join? Public challenges are posted to the announcement channel.',
            'error' => 'Please pick one of the options offered.',
            'expected' => 'Tap one of the buttons to choose who can join.',
        ],

        'awaiting_create_confirmation' => [
            'prompt' => 'Shall I create it?',
            'expected' => 'Tap Create it to go ahead, or Cancel to drop it.',
        ],
    ],

    'announce' => [
        'headline' => 'New challenge: :title',
        'details' => ':period · :periods periods · :proof',
        'join_button' => 'Join',
    ],

    /*
    | Reminder copy, one line per `ReminderKind` value. `:moment` is the boundary
    | the sentence is about (a start or a close), already rendered in the
    | challenge's own timezone, which is passed separately as `:timezone`.
    */
    'reminder' => [
        'challenge_starting' => '“:title” starts :moment (:timezone) — :total periods. Send /checkin every period to keep your streak.',
        'period_opened' => 'Period :index of :total in “:title” is open. Send /checkin when you have done the thing.',
        'period_ending' => 'Period :index of :total in “:title” closes at :moment (:timezone). Send /checkin now if you have not yet.',
    ],

    /*
    | The coin shop. `package` is one shelf of it, `pay_prompt`/`pay_button` the
    | message that carries Telegram's invoice link, and `invoice_title` /
    | `invoice_description` what Telegram itself shows in the payment sheet —
    | the title is capped at 32 characters by Telegram, so it stays terse.
    */
    'shop' => [
        'prompt' => 'Coins pay for extra challenge slots and freezes. Top up with Telegram Stars:',
        'package' => ':stars Stars → :coins coins',
        'button' => 'Buy :coins coins',
        'no_packages' => 'Top-ups are unavailable right now. Please try again later.',
        'pay_prompt' => 'Tap the button to pay :stars Stars for :coins coins.',
        'pay_button' => 'Pay :stars Stars',
        'credited' => 'Paid — :coins coins added. Balance: :balance',
        'not_credited' => 'Your payment arrived, but it could not be matched to a top-up. It has been logged and somebody will look at it.',
        'invoice_title' => ':coins coins',
        'invoice_description' => 'Top up your coin balance in :app.',
        'pre_checkout_error' => 'This top-up could not be completed. Please try again from /shop.',
    ],

    'language' => [
        'prompt' => 'Which language should I speak?',
        'set' => 'Done — from now on, :language.',
    ],

    /*
    | Linking a channel or group as a challenge's "home" chat. The `prompt_*`
    | lines are the one instruction the flow ever asks; everything else is a
    | verdict. The two `refused` verdicts name which admin check failed,
    | because their fixes differ: re-add the bot, or re-admin yourself.
    */
    'chatlink' => [
        'prompt_title' => 'Linking a chat to “:title”.',
        'prompt_add_bot' => 'First, add this bot as an administrator to your channel or group.',
        'prompt_forward' => 'Then forward any message from that chat to me here, and I will link it.',
        'prompt_cancel' => 'Send /cancel to give up.',
        'no_challenge' => 'Send this as /chatlink followed by your challenge’s join link payload, e.g. /chatlink j_abc123.',
        'challenge_gone' => 'That challenge is no longer available.',
        'not_forwarded' => 'That was not a message forwarded from a channel or group. Forward one from the chat you want to link.',
        'wrong_type' => 'That chat is neither a channel nor a group, so it cannot be linked.',
        'unreachable' => 'Telegram could not be reached to check the chat. Send the forward again in a moment.',
        'linked' => '“:title” is linked to “:challenge” and ready to receive posts.',
        'refused' => [
            'not_the_creator' => 'Only the creator of a challenge can link a chat to it.',
            'bot_not_admin' => 'I am not an administrator of “:title” yet (or cannot post there). Add me as an admin and forward another message.',
            'creator_not_admin' => 'You are not an administrator of “:title”. Only a chat admin can link it.',
            'proofs_not_public' => 'This challenge’s proofs are private, so they cannot be shared into a chat.',
        ],
        'revoked' => [
            'bot_not_admin' => '“:title” was unlinked from “:challenge”: I am no longer an administrator there. Re-add me to link it again.',
            'creator_not_admin' => '“:title” was unlinked from “:challenge”: you are no longer an administrator there.',
        ],
    ],

    'fallback' => [
        'unknown' => 'I did not follow that. Send /create to start a challenge, or /start to begin again.',
        'stale_button' => 'That button is no longer live. Send /create to start a challenge.',
    ],

    /*
    | What goes out into a linked chat. These lines are read by a mixed-language
    | audience, so they render in the platform's fallback locale — the same
    | choice the announcement channel makes. The check-in line carries only a
    | name, a period number and a streak: anything else about the participant
    | is not the chat's business.
    */
    'chatpost' => [
        'checkin' => ':name checked in for period :period of :total — streak: :streak 🔥',
        'leaderboard' => [
            'headline' => 'Top streaks in “:title”',
            'row' => ':rank. :name — :streak in a row',
            'not_admin' => 'Only administrators of this chat can ask for the leaderboard.',
            'cooldown' => 'The leaderboard was just posted. Try again in :minutes minute(s).',
            'empty' => 'Nobody is on the board yet — check in to get there first.',
        ],
    ],

];
