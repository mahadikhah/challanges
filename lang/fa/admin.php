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

];
