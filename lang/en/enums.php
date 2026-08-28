<?php

/*
|--------------------------------------------------------------------------
| Enum Labels
|--------------------------------------------------------------------------
|
| User-facing names for the backed enums, keyed by group (the enum's own
| snake_cased class name) then case value. See App\Enums\Concerns\
| HasTranslatedLabel — a new case needs nothing but a line here.
|
*/

return [

    'period_type' => [
        'daily' => 'Daily',
        'weekly' => 'Weekly',
        'monthly' => 'Monthly',
        'seasonal' => 'Seasonal',
        'yearly' => 'Yearly',
        'custom' => 'Custom',
    ],

    'challenge_visibility' => [
        'public' => 'Public',
        'invite_only' => 'Invite only',
    ],

    'proof_type' => [
        'button' => 'One tap',
        'text_autogen' => 'Type a phrase',
        'image_approval' => 'Photo, approved by the creator',
    ],

    'challenge_status' => [
        'scheduled' => 'Scheduled',
        'active' => 'Active',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],

    'participant_status' => [
        'active' => 'Active',
        'completed' => 'Completed',
        'left' => 'Left',
        'removed' => 'Removed',
    ],

    'check_in_status' => [
        'pending' => 'Not checked in',
        'submitted' => 'Awaiting review',
        'approved' => 'Done',
        'rejected' => 'Rejected',
        'missed' => 'Missed',
        'frozen' => 'Frozen',
    ],

    'coin_transaction_reason' => [
        'stars_purchase' => 'Coins purchased with Stars',
        'invite_credit' => 'Invite reward',
        'challenge_completion' => 'Challenge completed',
        'admin_credit' => 'Added by an admin',
        'create_slot_purchase' => 'Extra challenge to create',
        'join_slot_purchase' => 'Extra challenge to join',
        'freeze_purchase' => 'Extra freeze',
        'stars_refund' => 'Purchase refunded',
        'admin_debit' => 'Removed by an admin',
    ],

    'entitlement_type' => [
        'create_slot' => 'Challenge to create',
        'join_slot' => 'Challenge to join',
    ],

    'entitlement_source' => [
        'free_baseline' => 'Included',
        'coin_purchase' => 'Bought with coins',
        'admin_grant' => 'Granted by an admin',
    ],

    'invite_status' => [
        'pending' => 'Not used yet',
        'claimed' => 'Used by an existing member',
        'credited' => 'Used — you earned coins',
    ],

    'star_payment_status' => [
        'pending' => 'Awaiting payment',
        'paid' => 'Paid',
        'refunded' => 'Refunded',
        'failed' => 'Failed',
    ],

    'reminder_kind' => [
        'challenge_starting' => 'Challenge starting',
        'period_opened' => 'Check-in due',
        'period_ending' => 'Last chance to check in',
    ],

    'flow_type' => [
        'simple' => 'One submission',
        'timed_session' => 'Timed steps',
    ],

    'step_input_type' => [
        'button' => 'Button tap',
        'image' => 'Photo',
        'voice' => 'Voice message',
    ],

    'check_in_session_status' => [
        'in_progress' => 'In progress',
        'completed' => 'Completed',
        'expired' => 'Expired',
        'abandoned' => 'Abandoned',
    ],
];
