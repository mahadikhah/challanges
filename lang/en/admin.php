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

];
