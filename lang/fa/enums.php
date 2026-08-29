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
        'voice_approval' => 'پیام صوتی، با تأیید سازنده',
        'video_approval' => 'ویدیو، با تأیید سازنده',
    ],

    'approval_mode' => [
        'manual' => 'خودم عکس‌ها را بررسی می‌کنم',
        'ai' => 'هوش مصنوعی عکس‌ها را بررسی کند',
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

    'coin_transaction_reason' => [
        'stars_purchase' => 'خرید سکه با استارز',
        'bale_pay_purchase' => 'خرید سکه با پرداخت بله',
        'invite_credit' => 'پاداش دعوت',
        'challenge_completion' => 'تکمیل چالش',
        'admin_credit' => 'افزوده‌شده توسط مدیر',
        'create_slot_purchase' => 'ظرفیت ساخت چالش',
        'join_slot_purchase' => 'ظرفیت عضویت در چالش',
        'freeze_purchase' => 'فریز اضافه',
        'stars_refund' => 'بازگشت وجه خرید',
        'admin_debit' => 'کم‌شده توسط مدیر',
    ],

    'entitlement_type' => [
        'create_slot' => 'ظرفیت ساخت چالش',
        'join_slot' => 'ظرفیت عضویت در چالش',
    ],

    'entitlement_source' => [
        'free_baseline' => 'رایگان',
        'coin_purchase' => 'خریداری‌شده با سکه',
        'admin_grant' => 'اهدای مدیر',
    ],

    'invite_status' => [
        'pending' => 'استفاده نشده',
        'claimed' => 'کاربر قبلاً عضو بوده',
        'credited' => 'استفاده شد — سکه گرفتید',
    ],

    'star_payment_status' => [
        'pending' => 'در انتظار پرداخت',
        'paid' => 'پرداخت شد',
        'refunded' => 'بازگشت داده شد',
        'failed' => 'ناموفق',
    ],

    'payment_provider' => [
        'telegram_stars' => 'ستاره‌های تلگرام',
        'bale_pay' => 'پرداخت بله',
    ],

    'reminder_kind' => [
        'challenge_starting' => 'شروع چالش',
        'period_opened' => 'زمان ثبت فعالیت',
        'period_ending' => 'آخرین فرصت ثبت فعالیت',
    ],

    'flow_type' => [
        'simple' => 'یک‌مرحله‌ای',
        'timed_session' => 'مرحله‌ای زمان‌دار',
    ],

    'step_input_type' => [
        'button' => 'دکمه',
        'image' => 'عکس',
        'voice' => 'پیام صوتی',
        'video' => 'ویدیو',
    ],

    'check_in_session_status' => [
        'in_progress' => 'در جریان',
        'completed' => 'تکمیل شد',
        'expired' => 'منقضی شد',
        'abandoned' => 'رها شد',
    ],

    'scoring_type' => [
        'binary' => 'انجام شد یا نه',
        'quantity' => 'تعداد',
    ],

    'scoring_strategy' => [
        'proportional' => 'نسبتی',
    ],
];
