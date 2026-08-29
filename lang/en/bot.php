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
            'media_too_long' => 'That recording is too long for “:title” — send a shorter one.',
            'media_too_large' => 'That file is too big for “:title” — send a smaller one.',
        ],

        'review_refused' => [
            'not_the_reviewer' => 'Only the creator of that challenge can review it.',
            'already_settled' => 'That period has already closed, so the verdict can no longer change.',
            'not_awaiting_review' => 'There is no photo waiting on you for that one.',
        ],
    ],

    /*
    | The timed-session surface: what a participant sees while a session's
    | steps are under way. The confirmation line is deliberately absent — a
    | completed session is a settled check-in and says so with
    | `bot.checkin.confirmed` verbatim. `refused` lines are addressed by the
    | value of a `SessionRejection` case, exactly as the check-in group's are.
    */
    'session' => [
        'next_button' => 'Next',
        'stale' => 'That step is no longer the one waiting. Send /checkin to see where the session is.',
        'too_early' => ':seconds seconds left — the wait is part of this challenge.',
        'voice_too_long' => 'That voice message is :seconds seconds long; this step accepts up to :max.',
        'video_too_long' => 'That video is :seconds seconds long; this challenge accepts up to :max.',
        'media_too_large' => 'That file is too big for this challenge — send a smaller one.',
        'store_error' => 'That could not be received. Please send it again.',

        'step_button' => 'Step :step of :total in “:title” — tap Next once :wait has passed since the step before it.',
        'step_image' => 'Step :step of :total in “:title” — send a photo once :wait has passed since the step before it.',
        'step_voice' => 'Step :step of :total in “:title” — send a voice message of up to :max seconds once :wait has passed since the step before it.',

        'refused' => [
            'not_a_timed_challenge' => '“:title” does not check in through a session.',
            'not_a_participant' => 'You are not an active participant in “:title”.',
            'no_open_period' => '“:title” has no period open right now.',
            'already_settled' => 'That period is already settled.',
            'submission_missing' => 'That step needs more than a tap.',
            'unexpected_submission' => 'That step takes a tap, nothing else.',
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
        'add_step_button' => 'Add a step',
        'done_steps_button' => 'Done — check the design',

        'criteria_accept_button' => 'Use this',
        'criteria_edit_button' => 'Write my own',
        'criteria_no_suggestion' => 'I could not draft criteria right now, so please write your own — what should a valid check-in photo show?',
        'criteria_flagged' => 'Those criteria could not be accepted, so the challenge will use manual review instead. You can edit them and try again, or continue.',
        'criteria_unscreened' => 'I could not check those criteria just now, so the challenge will use manual review instead. You can try again in a moment, or continue.',

        'steps_too_long' => "Those steps can't work: their waits add up to at least :minimum, but one period of this challenge lasts only :period. Drop a step or shorten the waits.",

        'summary' => "Here is your challenge:\n\nTitle: :title\nDescription: :description\nPeriod: :period\nCustom length: :custom_days days\nStarts: :start (:timezone)\nPeriods: :periods\nProof: :proof\nVisibility: :visibility\nFlow: :flow\nFreezes each: :freezes",
        'summary_steps' => ':steps steps, at least :minimum of waiting per session (one period lasts :period)',
        'summary_approval' => 'Reviewed by AI against: “:criteria”',

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

        'awaiting_approval_mode' => [
            'prompt' => 'Who reviews the check-in photos?',
            'error' => 'Please pick one of the options offered.',
            'expected' => 'Tap one of the buttons to choose who reviews the photos.',
            'unavailable' => 'AI review is not available on this platform right now — review stays with you. Pick manual review to continue.',
        ],

        'awaiting_approval_criteria' => [
            'prompt' => 'What should a valid check-in photo show? One sentence, up to :criteria_max characters. The AI reviewer will judge every photo against it.',
            'error' => 'That needs to be one sentence of at most :criteria_max characters.',
            'expected' => 'Send the criteria as a text message.',
        ],

        'awaiting_approval_criteria_confirm' => [
            'prompt' => "Here is a suggestion based on your challenge:\n\n“:criteria”\n\nUse it as written, or write your own.",
            'error' => 'Please use the buttons on this question.',
            'expected' => 'Tap Use this, or Write my own.',
        ],

        'awaiting_visibility' => [
            'prompt' => 'Who can join? Public challenges are posted to the announcement channel.',
            'error' => 'Please pick one of the options offered.',
            'expected' => 'Tap one of the buttons to choose who can join.',
        ],

        'awaiting_flow_type' => [
            'prompt' => 'And how do participants check in? A timed session walks them through steps, each after a wait you set.',
            'error' => 'Please pick one of the flows offered.',
            'expected' => 'Tap one of the buttons to choose the flow.',
        ],

        /*
         | The step loop. Its `error` line doubles as the loop's refusals: an
         | unknown button, and a "done" with nothing designed yet.
         */
        'awaiting_step_loop' => [
            'prompt' => 'Add a step to the session, or tap Done to check the design.',
            'error' => 'Add at least one step before finishing.',
            'expected' => 'Tap Add a step or Done.',
        ],

        'awaiting_step_input_type' => [
            'prompt' => 'Step :step — what does the participant do?',
            'error' => 'Please pick one of the input types offered.',
            'expected' => 'Tap one of the buttons to choose what this step takes.',
        ],

        'awaiting_step_wait' => [
            'prompt' => 'How long must they wait before this step answers? Seconds, 0 to :wait_max.',
            'error' => 'Send a whole number of seconds between 0 and :wait_max.',
            'expected' => 'Send the wait in seconds as a text message.',
        ],

        'awaiting_step_voice_limit' => [
            'prompt' => 'What is the longest voice message this step accepts? Seconds, 1 to :voice_max.',
            'error' => 'Send a whole number of seconds between 1 and :voice_max.',
            'expected' => 'Send the limit in seconds as a text message.',
        ],

        'awaiting_step_label' => [
            'prompt' => 'Give this step a name, or tap Skip. Up to :label_max characters.',
            'error' => 'That name is longer than :label_max characters.',
            'expected' => 'Send a name as text, or tap Skip.',
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
    | The coin shop. `package` and `package_rial` are one shelf of it, priced in
    | the payer's own rail — Stars on Telegram, Rial on Bale — and
    | `pay_prompt`/`pay_button` the message that carries Telegram's invoice link.
    | `invoice_title`/`invoice_description` are what the payment sheet itself
    | shows — the title is capped at 32 characters by Telegram, so it stays terse.
    */
    'shop' => [
        'prompt' => 'Coins pay for extra challenge slots and freezes. Top up:',
        'package' => ':stars Stars → :coins coins',
        'package_rial' => ':rial Rial → :coins coins',
        'button' => 'Buy :coins coins',
        'no_packages' => 'Top-ups are unavailable right now. Please try again later.',
        'pay_prompt' => 'Tap the button to pay :stars Stars for :coins coins.',
        'pay_button' => 'Pay :stars Stars',
        'credited' => 'Paid — :coins coins added. Balance: :balance',
        'not_credited' => 'Your payment arrived, but it could not be matched to a top-up. It has been logged and somebody will look at it.',
        'pending_confirmation' => 'Your payment is on its way through. The coins land as soon as it settles — no need to do anything.',
        'invoice_title' => ':coins coins',
        'invoice_description' => 'Top up your coin balance in :app.',
        'pre_checkout_error' => 'This top-up could not be completed. Please try again from /shop.',
        'unavailable' => 'Top-ups are not available here yet. They will be soon.',
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
