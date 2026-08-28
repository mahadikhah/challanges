<?php

return [

    'settings' => [
        'title' => 'Settings',
        'description' => 'Every rate and price the platform runs on. An empty value reverts to the default.',

        'groups' => [
            'economy' => 'Economy',
            'baseline' => 'Free baseline & defaults',
            'access' => 'Access & tokens',
            'reminders' => 'Reminders',
        ],

        'keys' => [
            'invite_coin_reward' => 'Coins per credited invite',
            'create_slot_coin_price' => 'Create-slot price (coins)',
            'join_slot_coin_price' => 'Join-slot price (coins)',
            'freeze_coin_price' => 'Freeze price (coins)',
            'challenge_completion_coin_reward' => 'Completion reward (coins)',
            'stars_packages' => 'Stars → coins packages',
            'free_create_slots' => 'Free create-slots per user',
            'free_join_slots' => 'Free join-slots per user',
            'default_challenge_freezes' => 'Default freezes per challenge',
            'required_channel' => 'Required announcement channel',
            'channel_verification_ttl_minutes' => 'Channel check freshness (minutes)',
            'miniapp_token_ttl_minutes' => 'Mini App token lifetime (minutes)',
            'initdata_max_age_seconds' => 'initData maximum age (seconds)',
            'conversation_ttl_minutes' => 'Bot wizard lifetime (minutes)',
            'reminder_ending_lead_hours' => '“Period ending” lead (hours)',
        ],

        'value' => 'Value',
        'default' => 'Default',
        'overridden' => 'Overridden',
        'save' => 'Save',
        'reset' => 'Reset to default',
        'saved' => 'Setting saved.',
        'reset_done' => 'Setting reverted to its default.',
        'invalid_value' => 'That value does not fit this setting.',
        'stars' => 'Stars',
        'coins' => 'Coins',
        'add_package' => 'Add package',
        'remove_package' => 'Remove',
    ],

    'reviews' => [
        'title' => 'Proof review',
        'description' => 'Photos waiting on a verdict. Approving settles the period and moves the streak; rejecting lets the participant send another until the period closes.',

        'challenge' => 'Challenge',
        'participant' => 'Participant',
        'period' => 'Period',
        'submitted_at' => 'Submitted',
        'proof' => 'Proof',
        'view_proof' => 'Open full size',
        'approve' => 'Approve',
        'reject' => 'Reject',
        'empty' => 'Nothing is waiting on a verdict.',

        'approved' => 'Proof approved — the streak moved.',
        'rejected' => 'Proof rejected — the participant may send another.',

        'refused' => [
            'already_settled' => 'That period has already closed and been settled.',
            'not_awaiting_review' => 'That check-in no longer has a photo waiting on a verdict.',
            'not_the_reviewer' => 'Only the creator of that challenge or an admin can review it.',
        ],
    ],

    'challenges' => [
        'title' => 'Challenges',
        'description' => 'Every challenge on the platform, newest first.',
        'search' => 'Search by title…',
        'filter' => [
            'all' => 'All statuses',
        ],

        'challenge' => 'Challenge',
        'creator' => 'Creator',
        'participants' => 'Participants',
        'periods' => 'Periods',
        'starts_at' => 'Starts',
        'status' => 'Status',
        'proof' => 'Proof',
        'cadence' => 'Cadence',
        'timezone' => 'Timezone',
        'visibility' => 'Visibility',
        'description_label' => 'Description',
        'default_freezes' => 'Freezes each',
        'announced_at' => 'Announced',
        'empty' => 'No challenges match.',
        'next_page' => 'Older',
        'view' => 'Open',

        'streak' => 'Streak',
        'best' => 'Best',
        'freezes' => 'Freezes used',
        'joined_at' => 'Joined',

        'cancel' => 'Cancel challenge',
        'cancel_confirm' => 'Cancel this challenge? Its timeline stops and every active participant is notified. This cannot be undone.',
        'cancelled' => 'Challenge cancelled — participants are being notified.',
        'cancel_refused' => 'That challenge can no longer be cancelled.',
    ],

];
