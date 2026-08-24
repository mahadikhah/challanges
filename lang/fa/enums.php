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
        'daily' => 'روزانه',
        'weekly' => 'هفتگی',
        'monthly' => 'ماهانه',
        'seasonal' => 'فصلی',
        'yearly' => 'سالانه',
        'custom' => 'دلخواه',
    ],

    'challenge_visibility' => [
        'public' => 'عمومی',
        'invite_only' => 'فقط با دعوت',
    ],

    'proof_type' => [
        'button' => 'یک لمس',
        'text_autogen' => 'نوشتن یک عبارت',
        'image_approval' => 'عکس، با تأیید سازنده',
    ],

    'challenge_status' => [
        'scheduled' => 'زمان‌بندی‌شده',
        'active' => 'در جریان',
        'completed' => 'تمام‌شده',
        'cancelled' => 'لغو‌شده',
    ],

    'participant_status' => [
        'active' => 'فعال',
        'completed' => 'تمام‌شده',
        'left' => 'خارج‌شده',
        'removed' => 'حذف‌شده',
    ],

    'check_in_status' => [
        'pending' => 'ثبت نشده',
        'submitted' => 'در انتظار بررسی',
        'approved' => 'انجام شد',
        'rejected' => 'رد شد',
        'missed' => 'از دست رفت',
        'frozen' => 'فریز شد',
    ],

];
