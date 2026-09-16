# استقرار روی هاست اشتراکی (cPanel)

> **English:** this guide is also available in English — [setup-cpanel.md](setup-cpanel.md)

این راهنما پلتفرم چالش‌ها را روی یک هاست اشتراکی cPanel مستقر می‌کند. این راهنما نحوه استقرار **همین پروژه مشخص** را بازتاب می‌دهد — فقط MySQL، بدون Redis در هیچ‌جا، صفِ کار درایور‌شده با cron — نه توصیه‌های عمومی Laravel. برای استقرار روی VPS (با Supervisor و کارگر صف واقعی) [setup-vps.fa.md](setup-vps.fa.md) را ببینید.

## ۱. پیش‌نیازها

| نیاز | دلیل |
|---|---|
| **PHP 8.3+** ‏(8.4 هم خوب) | کدبیس برای `^8.3` نوشته شده |
| **MySQL 8.4** (یا نزدیک‌ترین نسخه موجود هاست) | کوئری‌های دوره/استریک، قفل سطر، یکتای مرکب |
| **Composer** (از طریق SSH یا نصب‌کننده هاست) | نصب وابستگی‌ها |
| **دسترسی به cron job** | هم زمان‌بند و هم کارگر صف اینجا از cron اجرا می‌شوند |
| **HTTPS روی دامنه — الزامی** | تلگرام و بله هر دو URL وبهوک غیر HTTPS را رد می‌کنند؛ فرمان `telegram:set-webhook` از ثبت URL با `http://` سر باز می‌زند |
| دسترسی SSH (به‌شدت توصیه‌شود) | بخش بزرگی از این راهنما کار خط فرمان است |

Redis نه لازم است و نه مطلوب: صف، کش و نشست‌ها همه از درایور `database` استفاده می‌کنند — بند ۳ را ببینید.

## ۲. رساندن کد به سرور

با SSH و دسترسی git:

```bash
cd ~/yourdomain.com            # دایرکتوری دامنه/ساب‌دامنه شما
git clone <your-repo-url> .
```

بدون git: روی ماشین خودتان مخزن را clone یا آرشیو release را دانلود کنید، سپس با File Manager سی‌پنل آپلود و استخراج کنید.

**Document root را روی `public/` بگذارید.** در cPanel: *Domains → دامنه شما → Document Root* →
`/home/USER/yourdomain.com/public`. Laravel هرگز نباید از ریشه خودش سرو شود؛ `public/` تنها پوشه‌ای است که باید رو به وب باشد.

اگر هاست اجازه تغییر document root را نمی‌دهد، مخزن را در پوشه دامنه نگه دارید و یک `.htaccess` در ریشه دامنه بگذارید که همه‌چیز را به `public/` بازنویسی کند:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

تغییر document root راه بهتر است — هر جا هاست اجازه می‌دهد همان را انجام دهید.

## ۳. پیکربندی `.env`

`.env.example` را به `.env` کپی و پر کنید. مرجع کامل متغیرها در [README](../README.fa.md#متغیرهای-محیطی) است. آن‌هایی که سرنوشت این استقرار را تعیین می‌کنند:

```bash
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com        # جایگزین کنید — باید https باشد

DB_CONNECTION=mysql
DB_HOST=localhost                     # هاست‌های اشتراکی MySQL را به‌صورت محلی ارائه می‌کنند
DB_PORT=3306
DB_DATABASE=your_db_name              # از cPanel → MySQL Databases
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
```

**چرا هر سه `database` هستند:** این پلتفرم عمداً بدون Redis اجرا می‌شود — هاست اشتراکی آن را نمی‌دهد و کد برای همین دنیا نوشته شده (پخش زمان‌بندی‌شده یادآوری‌ها به‌جای بروکر سریع، idempotency با قید یکتا به‌جای store جداکردن). گذاشتن `SESSION_DRIVER=redis` یا مشابه آن اینجا فقط «شکستن» نیست — قرارداد استقراری را که کد بر اساسش ساخته شده می‌شکند. هر سه را روی `database` نگه دارید.

سپس متغیرهای ربات (هر دو اختیاری‌اند — فقط پلتفرم‌هایی را اجرا کنید که می‌خواهید):

```bash
TELEGRAM_BOT_TOKEN=123456:ABC...              # از @BotFather
TELEGRAM_BOT_USERNAME=your_challenges_bot
TELEGRAM_WEBHOOK_SECRET=<رشته تصادفی ۳۲+ کاراکتری>   # بخشی از مسیر URL وبهوک
TELEGRAM_WEBHOOK_HEADER_SECRET=<رشته تصادفی ۳۲+>     # به‌عنوان هدر راز تلگرام بازگردانده می‌شود
TELEGRAM_REQUIRED_CHANNEL=@your_channel       # کانال دروازه دسترسی
MINIAPP_URL="${APP_URL}/miniapp"

BALE_BOT_TOKEN=...
BALE_BOT_USERNAME=...
BALE_WEBHOOK_SECRET=<رشته تصادفی ۳۲+ کاراکتری>       # تنها راز وبهوک بله (هدری وجود ندارد)
BALE_REQUIRED_CHANNEL=@your_bale_channel
BALE_PROVIDER_TOKEN=...                       # توکن کیف پول از @botfather، نه توکن ربات
```

تولید دو راز تصادفی روی ماشین خودتان:

```bash
openssl rand -hex 24    # دوبار اجرا کنید، یک خروجی برای هر راز
```

و در پایان:

```bash
php artisan key:generate
```

همه نرخ‌ها، قیمت‌ها، محدودیت‌های هوش مصنوعی و مانند آن در جدول دیتابیس `settings` از طریق پنل مدیر مدیریت می‌شوند — هرگز در env.

## ۴. وابستگی‌ها و مایگریشن‌ها

```bash
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
```

اگر composer در PATH نیست، از مسیری که cPanel هنگام فعال‌سازی SSH چاپ کرده استفاده کنید (معمولاً `/opt/cpanel/composer/bin/composer` یا `/usr/local/bin/composer`).

## ۵. فایل‌های فرانت‌اند

بیلد دو ورودی Vite را کامپایل می‌کند — باندل مدیر/وب‌سایت و SPA مینی‌اپ. هاست‌های اشتراکی معمولاً Node ندارند.

**مسیر اصلی — بیلد محلی، آپلود خروجی:**

```bash
# روی ماشین خودتان
npm ci
npm run build
```

سپس پوشه تولیدشده `public/build/` (کل پوشه) را در `public/build/` سرور آپلود کنید. سرور فقط همین را لازم دارد — خروجی Vite فایل‌های استاتیک با نام‌های hash‌دار به‌علاوه یک manifest است که Laravel می‌خواند.

**جایگزین — بیلد روی سرور** اگر هاست Node 20+ از طریق SSH می‌دهد:

```bash
npm ci
npm run build
```

## ۶. ورودی‌های cron

هاست اشتراکی نه Supervisor دارد و نه اجازه daemon؛ پس هم زمان‌بند و هم کارگر صف از cron اجرا می‌شوند. در cPanel: *Cron Jobs → Add New Cron Job*، یا ویرایش crontab با SSH:

```cron
* * * * * cd /home/USER/yourdomain.com && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/USER/yourdomain.com && /usr/local/bin/php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

مسیر `/usr/local/bin/php` را با باینری PHP 8.3+ خط فرمان هاست خود تنظیم کنید (با SSH: `which php`؛ در cPanel اغلب `/opt/cpanel/ea-php84/root/usr/bin/php` و مشابه آن). `USER`/`yourdomain.com` جای‌نگهدارند — جایگزین کنید.

**زمان‌بند چه چیزهایی اجرا می‌کند** (از قبل در `routes/console.php` سیم‌کشی شده و به‌موجب طراحی idempotent است): بستن دوره‌ها، ساخت/ارسال یادآوری‌ها و پست‌های جدول امتیاز هر دقیقه؛ هرس توکن‌های منقضی مینی‌اپ، جارو پرداخت‌های رهاشده و پنجره نگهداشت رسانه اثبات به‌صورت روزانه.

**چرا `--stop-when-empty --max-time=55`:** هر تیک cron یک کارگر را شروع می‌کند، اجازه می‌دهد صف را تخلیه کند، و پیش از تیک بعدی می‌کشدش — بدون هم‌پوشانی، بدون daemon. این **توان عملیاتی را تقریباً به یک دقیقه کار در دقیقه محدود می‌کند**. اینجا این مورد انتظار دارد و اشتباه استقراری نیست: کد دقیقاً به این دلیل که این شکل استقرارِ طراحی‌شده است، ارسال یادآوری‌ها و اعلان‌ها را یک ارسال در ثانیه پخش می‌کند. اگر صف همیشه سریع‌تر از تخلیه یک کارگر رشد کند، پاسخ راهنمای VPS است، نه خط‌های cron بیشتر.

## ۷. ثبت وبهوک‌ها

### تلگرام

یک فرمان، وبهوک را با **هر دو** راز خودش هم‌گام ثبت می‌کند:

```bash
php artisan telegram:set-webhook
```

این فرمان `TELEGRAM_WEBHOOK_SECRET` (مسیر) و `TELEGRAM_WEBHOOK_HEADER_SECRET` (هدر) را از `.env` می‌خواند، از ثبت URL با `http://` سر باز می‌زند و از تلگرام می‌خواهد فقط انواع آپدیت handledشده توسط روتر را تحویل دهد. در نخستین استقرار `--drop-pending-updates` را اضافه کنید تا هر چه تا حالا صف شده دور ریخته شود.

همین فرمان **فهرست فرمان‌های ربات** را هم ثبت می‌کند — همان لیستی که زیر دکمهٔ منو می‌آید — یک بار برای هر زبان پشتیبانی‌شده، به‌علاوهٔ یک نسخهٔ بدون زبان که بقیهٔ زبان‌های کلاینت را پوشش می‌دهد. بعد از تغییر خط‌های `bot.commands.*` در `lang/` دوباره اجرایش کنید، وگرنه فهرست با متن قبلی می‌ماند.

بررسی:

```bash
php artisan telegram:webhook-info
```

ثبت دستی از طریق BotFather یا فراخوانی خام `setWebhook` با فقط یکی از دو راز، شکست خاموش کلاسیک است: endpoint هر دو را الزامی می‌کند، پس هر آپدیت پیش از رسیدن به هر چیزی که لاگ بزند 404 می‌شود و ربات فقط ساکت می‌شود. همیشه از فرمان استفاده کنید.

### بله

بله **سازوکار راز هدری ندارد** — ‏`setWebhook` فقط URL می‌پذیرد — پس بخش مسیرِ تصادفی `BALE_WEBHOOK_SECRET` کل سازوکار اصالت است. فرمان artisanی برای وبهوک بله وجود ندارد (چرخه حیات وبهوک به‌موجب طراحی فقط تلگرامی است)؛ یک بار با tinker ثبتش کنید:

```bash
php artisan tinker --execute='
$api = new Telegram\Bot\Api(
    token: config("services.bale.bot_token"),
    async: false,
    httpClientHandler: app(App\Services\Telegram\LaravelHttpClient::class),
    baseBotUrl: "https://tapi.bale.ai/bot",
);
$api->setWebhook(["url" => route("bale.webhook", ["token" => config("services.bale.webhook_secret")])]);
echo route("bale.webhook", ["token" => config("services.bale.webhook_secret")]).PHP_EOL;
'
```

محدودیت‌های راستی‌آزمایی‌شده در docs.bale.ai: URL باید روی HTTPS و پورت 443 (یا 88) باشد. endpoint روی راز مسیر اشتباه 404 می‌دهد — یک probe نمی‌تواند راز اشتباه را از مسیر روترنشده تشخیص دهد.

## ۸. مجوزهای فایل

```bash
chmod -R ug+w storage bootstrap/cache
```

هر دو دایرکتوری باید برای کاربر وب‌سرور قابل نوشتن باشند. در cPanel با suPHP/LSAPI معمولاً مالکیت فایل کافی است؛ اگر پیام "permission denied" دیدید (یا لاگی در `storage/logs/laravel.log` نیست)، دلیل همین است.

## ۹. دام‌های cPanel

- **توابع PHP غیرفعال**: برخی هاست‌ها `proc_open`/`putenv` را غیرفعال می‌کنند که Composer و برخی فرمان‌های artisan به آن‌ها نیاز دارند. *MultiPHP INI Editor → disable_functions* را بررسی کنید؛ حداقل این دو را برای باینری CLI استفاده‌شده در cron بردارید.
- **`max_execution_time`** با `--max-time=55` تعامل دارد: کارگر صف خودش را ۵۵ ثانیه محدود می‌کند، اما اگر `max_execution_time` پی‌اچ‌پی زیر حدود ۶۰ باشد پروسه وسط کار می‌میرد. jobها دوباره اجرا می‌شوند، اما برای اطمینان `max_execution_time=60` (یا بالاتر) را برای PHP خط فرمان بگذارید.
- **حافظه**: `composer install` حدود ۱G می‌خواهد؛ اگر شکست خورد با `php -d memory_limit=1G /usr/local/bin/composer install ...` اجرا کنید.
- **OPcache**: برای PHP وب فعالش کنید؛ PHP کرون INI جداگانه دارد — با `php -i | grep php.ini` از طریق SSH بررسی کنید، نه چیزی که cPanel برای وب نشان می‌دهد.
- **`public/.htaccess`**: همراه مخزن می‌آید (استاندارد Laravel). اگر همه URLها جز صفحه اصلی 404 شدند، `mod_rewrite` برای حساب خاموش است — با هاست تماس بگیرید.
- **نسخه MySQL**: اگر هاست فقط 5.7/8.0 می‌دهد، اپ احتمالاً اجرا می‌شود اما 8.4 نسخه‌ای است که پروژه رویش تست شده؛ هاست با 8.x را ترجیح دهید.

## ۱۰. عیب‌یابی

| علامت | علت محتمل → راه‌حل |
|---|---|
| ربات بعد از استقرار ساکت، لاگی نیست | وبهوک با یک راز یا URL اشتباه ثبت شده → `php artisan telegram:webhook-info`، دوباره `php artisan telegram:set-webhook` |
| ربات ساکت **و** لاگ خالی **و** webhook-info درست | cron اجرا نمی‌شود → خروجی ایمیل *Cron Jobs* را ببینید؛ یک بار `php artisan schedule:run` را دستی اجرا کنید |
| ربات پاسخ می‌دهد اما یادآوری/اعلان‌ها چند دقیقه تأخیر دارند یا نمی‌رسند | cron کارگر صف اجرا نمی‌شود یا فوراً می‌میرد → `php artisan queue:work --stop-when-empty --max-time=55` را دستی اجرا کنید؛ `php -v` را بررسی کنید 8.3+ باشد |
| "could not find driver" (pdo_mysql) | باینری PHP اشتباه در cron → crontab را به باینری ea-php83/84 اشاره دهید |
| `ViteException: Unable to locate file in Vite manifest` | `public/build/` غایب یا کهنه → محلی بیلد بگیرید و دوباره آپلود کنید |
| تلگرام ثبت وبهوک را رد کرد | `APP_URL` با `https://` نیست — APP_URL را درست کنید و `php artisan config:clear` |
| پرداخت در تلگرام کار می‌کند اما فروشگاه بله می‌گوید غیرفعال است | `BALE_PROVIDER_TOKEN` خالی است — پرداخت بله بدون توکن کیف پول اجرا نمی‌شود |
| 500 روی همه صفحات، لاگ خالی | `storage/` قابل نوشتن نیست → بند ۸ |
| مقادیر کهنه env بعد از ویرایش `.env` می‌مانند | config کش شده → `php artisan config:clear` (و در استقرار مجدد `php artisan optimize:clear`) |

در هر به‌روزرسانی کد: `git pull` (یا آپلود مجدد) → `composer install --no-dev --optimize-autoloader` → `php artisan migrate --force` → بیلد/آپلود مجدد `public/build` → `php artisan optimize:clear`.
