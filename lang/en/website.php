<?php

/*

| Marketing copy for the public landing page — the only page whose job is to
| explain the platform to someone who has never opened the bot.

*/

return [

    'title' => 'Challenges that hold you to your word',
    'lead' => 'Create a challenge, invite your friends, and check in every day, week or month — right inside Telegram. Miss a check-in and your streak resets. Everyone can see it happen.',

    'open_bot' => 'Open the bot in Telegram',
    'bot_unavailable' => 'The bot link is not configured yet — it will appear here as soon as it is.',

    'login' => 'Admin log in',
    'dashboard' => 'Dashboard',

    // The three-column feature band under the hero.
    'features' => [
        'title' => 'Accountability, not good intentions',

        'streaks' => [
            'title' => 'Streaks & freezes',
            'body' => 'Every check-in extends your streak; a miss without a freeze resets it to zero — but never removes you from the challenge. Each challenge hands out freezes so one bad week doesn\'t cost you everything.',
        ],

        'proof' => [
            'title' => 'Proof that fits the promise',
            'body' => 'One-tap check-ins for habit challenges, a unique generated phrase the system expects to hear back for low-friction honesty, or a photo the creator personally approves. You pick per challenge.',
        ],

        'timeline' => [
            'title' => 'One shared clock',
            'body' => 'A challenge runs on a single fixed timeline in the creator\'s timezone. Late joiners are never charged for periods they weren\'t in — their obligations begin when they do.',
        ],
    ],

    // The economy band.
    'economy' => [
        'title' => 'Coins, earned and spent',
        'body' => 'Start free — one challenge to create, one to join. Earn coins by inviting friends who are new to the bot and by finishing what you started, then spend them on extra slots and more freezes.',

        'invite' => [
            'title' => 'Credited invites',
            'body' => 'Share your link; when someone brand-new to the bot arrives through it, coins land in your balance.',
        ],
        'completion' => [
            'title' => 'Completion rewards',
            'body' => 'Finish every period of a challenge and the platform pays a flat reward on top.',
        ],
        'stars' => [
            'title' => 'Top up with Stars',
            'body' => 'Buy coins with Telegram Stars — digital goods only, no wagering, no prize pools.',
        ],
    ],

    // The numbered how-it-works band.
    'how' => [
        'title' => 'How it works',

        'start' => [
            'title' => 'Start the bot',
            'body' => 'One tap opens a conversation in Telegram. Join the announcement channel and you\'re in.',
        ],
        'create' => [
            'title' => 'Create or join',
            'body' => 'Set the cadence — daily to yearly — the proof type, and who can see it. Or follow a friend\'s invite link.',
        ],
        'checkin' => [
            'title' => 'Check in every period',
            'body' => 'The bot reminds you when a period is ending. One tap, one phrase, or one photo.',
        ],
        'streak' => [
            'title' => 'Keep the streak',
            'body' => 'Watch your streak grow, spend a freeze when life happens, and collect your reward at the finish line.',
        ],
    ],

    // The illustrative challenge showcase — three example cards in the Mini
    // App's idiom. The numbers are baked into the component; only the copy
    // lives here.
    'showcase' => [
        'title' => 'What a challenge looks like',
        'note' => 'Illustrative examples — the real ones live in the bot.',
        'streak' => 'streak',
        'freezes_left' => 'freezes left',

        'morning' => [
            'title' => 'Morning run',
            'meta' => 'Daily · 12 participants',
            'progress' => 'Day 34 of 60',
        ],
        'reading' => [
            'title' => 'A book a week',
            'meta' => 'Weekly · 6 participants',
            'progress' => 'Week 9 of 12',
        ],
        'language' => [
            'title' => 'Language, every day',
            'meta' => 'Monthly · 21 participants',
            'progress' => 'Month 2 of 6',
        ],
    ],

    // Footer links into the repository's markdown, at the blob URL of the
    // default branch. The Farsi page links the `.fa` sibling of each doc.
    'docs' => [
        'readme' => 'About the project',
        'setup_vps' => 'Self-host on a VPS',
        'setup_cpanel' => 'Self-host on cPanel',
        'user_flows' => 'User flows',
    ],

    'footer' => 'Built on Telegram. English and Farsi, from day one.',

];
