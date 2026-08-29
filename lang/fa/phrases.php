<?php

/*
|--------------------------------------------------------------------------
| Check-in Phrase Vocabulary — Farsi
|--------------------------------------------------------------------------
|
| See the English file for the two rules every entry must satisfy. Farsi adds
| three of its own, all covered by the same test:
|
| 1. **Word order is not a translation.** Persian puts the adjective after the
|    noun, so the template differs from English rather than reusing it. A
|    locale that reads right-to-left is not the English template reversed.
| 2. **No ZWNJ (U+200C).** The normaliser folds a zero-width non-joiner to a
|    space, so a word written with one (نقره‌ای) would be stored as two — and
|    no participant would type the invisible character back the same way.
|    Every word here is written without one.
| 3. **Persian ی (U+06CC) and ک (U+06A9), never the Arabic ي and ك.** They look
|    near-identical and phone keyboards disagree about which they emit. The
|    normaliser folds Arabic to Persian, so the *stored* form has to be the
|    Persian one or it would never equal its own normalised form.
|
| Digits stay ASCII for the same reason: the normaliser folds ۰-۹ to 0-9, so a
| participant may type either and the canonical form is the ASCII one.
|
*/

return [

    'template' => ':noun :adjective :number',

    'adjectives' => [
        'آبی', 'آرام', 'آزاد', 'باریک', 'بزرگ', 'بلند', 'بیدار', 'پاک',
        'پهن', 'تازه', 'تند', 'تیز', 'خشک', 'خوش', 'دلیر', 'روشن',
        'زرد', 'زیبا', 'ساده', 'سبز', 'سبک', 'سرد', 'سفید', 'سنگین',
        'شاد', 'شیرین', 'طلایی', 'عمیق', 'قدیمی', 'قرمز', 'کهن', 'کوچک',
        'گرم', 'محکم', 'مهربان', 'نرم',
    ],

    'nouns' => [
        'آسمان', 'آهو', 'آینه', 'اسب', 'انار', 'باد', 'بادبان', 'باران',
        'باغ', 'بندر', 'بهار', 'برف', 'برگ', 'پرنده', 'پل', 'پنجره',
        'پاییز', 'تابستان', 'تپه', 'جاده', 'جنگل', 'چراغ', 'چشمه', 'خانه',
        'خرگوش', 'خورشید', 'دریا', 'درخت', 'دشت', 'دفتر', 'رود', 'روستا',
        'زمستان', 'زمین', 'زیتون', 'ساعت', 'ستاره', 'سنگ', 'سیب', 'شب',
        'شبنم', 'شن', 'شهر', 'شیر', 'صبح', 'صدف', 'فانوس', 'قایق',
        'قلم', 'کبوتر', 'کتاب', 'کلید', 'کوه', 'گل', 'گنجشک', 'گندم',
        'لنگر', 'ماه', 'نامه', 'نسیم',
    ],

];
