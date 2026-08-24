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

];
