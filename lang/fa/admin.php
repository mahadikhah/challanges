<?php

/*
| رابط کاربری پنل مدیریت.
*/

return [

    'settings' => [
        'title' => 'تنظیمات',
        'description' => 'تمام نرخ‌ها و قیمت‌هایی که پلتفرم روی آن‌ها اجرا می‌شود. مقدار خالی به پیش‌فرض برمی‌گردد.',

        'groups' => [
            'economy' => 'اقتصاد',
            'baseline' => 'پایه رایگان و پیش‌فرض‌ها',
            'access' => 'دسترسی و توکن‌ها',
            'reminders' => 'یادآورها',
        ],

        'keys' => [
            'invite_coin_reward' => 'سکه به ازای هر دعوت تأییدشده',
            'create_slot_coin_price' => 'قیمت ظرفیت ساخت (سکه)',
            'join_slot_coin_price' => 'قیمت ظرفیت عضویت (سکه)',
            'freeze_coin_price' => 'قیمت فریز (سکه)',
            'challenge_completion_coin_reward' => 'پاداش اتمام چالش (سکه)',
            'stars_packages' => 'بسته‌های ستاره → سکه',
            'free_create_slots' => 'ظرفیت ساخت رایگان هر کاربر',
            'free_join_slots' => 'ظرفیت عضویت رایگان هر کاربر',
            'default_challenge_freezes' => 'فریزهای پیش‌فرض هر چالش',
            'required_channel' => 'کانال اطلاع‌رسانی مورد نیاز',
            'channel_verification_ttl_minutes' => 'تازگی بررسی کانال (دقیقه)',
            'miniapp_token_ttl_minutes' => 'عمر توکن مینی‌اپ (دقیقه)',
            'initdata_max_age_seconds' => 'حداکثر عمر initData (ثانیه)',
            'conversation_ttl_minutes' => 'عمر ویزارد ربات (دقیقه)',
            'reminder_ending_lead_hours' => 'پیش‌گرفتی «نزدیک پایان دوره» (ساعت)',
        ],

        'value' => 'مقدار',
        'default' => 'پیش‌فرض',
        'overridden' => 'بازنویسی‌شده',
        'save' => 'ذخیره',
        'reset' => 'بازگشت به پیش‌فرض',
        'saved' => 'تنظیم ذخیره شد.',
        'reset_done' => 'تنظیم به پیش‌فرض بازگشت.',
        'invalid_value' => 'این مقدار با این تنظیم نمی‌سازد.',
        'stars' => 'ستاره',
        'coins' => 'سکه',
        'add_package' => 'افزودن بسته',
        'remove_package' => 'حذف',
    ],

    'reviews' => [
        'title' => 'بازبینی مدرک',
        'description' => 'عکس‌هایی که منتظر تصمیم‌اند. پذیرش، دوره را تسویه و رشته را جابه‌جا می‌کند؛ رد، به شرکت‌کننده اجازه می‌دهد تا بسته‌شدن دوره عکس دیگری بفرستد.',

        'challenge' => 'چالش',
        'participant' => 'شرکت‌کننده',
        'period' => 'دوره',
        'submitted_at' => 'زمان ارسال',
        'proof' => 'مدرک',
        'view_proof' => 'دیدن در اندازه کامل',
        'approve' => 'پذیرش',
        'reject' => 'رد',
        'empty' => 'چیزی منتظر تصمیم نیست.',

        'approved' => 'مدرک پذیرفته شد — رشته ثبت شد.',
        'rejected' => 'مدرک رد شد — شرکت‌کننده می‌تواند عکس دیگری بفرستد.',

        'refused' => [
            'already_settled' => 'آن دوره بسته و تسویه شده است.',
            'not_awaiting_review' => 'برای آن ثبت‌وضعیت دیگر عکسی در انتظار تصمیم نیست.',
            'not_the_reviewer' => 'فقط سازندهٔ آن چالش یا مدیر می‌تواند بازبینی کند.',
        ],
    ],

    'challenges' => [
        'title' => 'چالش‌ها',
        'description' => 'همهٔ چالش‌های پلتفرم، از تازه‌ترین.',
        'search' => 'جست‌وجو بر اساس عنوان…',
        'filter' => [
            'all' => 'همهٔ وضعیت‌ها',
        ],

        'challenge' => 'چالش',
        'creator' => 'سازنده',
        'participants' => 'شرکت‌کنندگان',
        'periods' => 'دوره‌ها',
        'starts_at' => 'شروع',
        'status' => 'وضعیت',
        'proof' => 'مدرک',
        'cadence' => 'ریتم',
        'timezone' => 'منطقهٔ زمانی',
        'visibility' => 'دید',
        'description_label' => 'توضیح',
        'default_freezes' => 'فریز هر نفر',
        'announced_at' => 'اعلام‌شده',
        'empty' => 'چالشی مطابق نیست.',
        'next_page' => 'قدیمی‌تر',
        'view' => 'باز کردن',

        'streak' => 'رشته',
        'best' => 'بهترین',
        'freezes' => 'فریز مصرف‌شده',
        'joined_at' => 'عضویت',

        'cancel' => 'لغو چالش',
        'cancel_confirm' => 'این چالش لغو شود؟ خط زمانی‌اش متوقف می‌شود و همهٔ شرکت‌کنندگان فعال باخبر می‌شوند. این کار برگشت‌پذیر نیست.',
        'cancelled' => 'چالش لغو شد — به شرکت‌کنندگان اطلاع داده می‌شود.',
        'cancel_refused' => 'این چالش دیگر قابل لغو نیست.',
    ],

];
