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
        'next_steps' => 'More is on the way — creating and joining challenges lands here shortly.',
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

    'fallback' => [
        'unknown' => 'I did not follow that. Send /start to begin.',
    ],

];
