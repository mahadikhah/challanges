# استقرار روی VPS اوبونتو

> **English:** this guide is also available in English — [setup-vps.md](setup-vps.md)

این راهنما پلتفرم چالش‌ها را روی یک VPS تازه اوبونتو 24.04 با Nginx، PHP-FPM، MySQL و Supervisor مستقر می‌کند — یک **کارگر صف واقعی و طولانی‌مدت**، که همان چیزی است که این محیط نسبت به استقرار cron-محدود هاست اشتراکی در [setup-cpanel.fa.md](setup-cpanel.fa.md) ارائه می‌دهد.

> **روی سرور فقط داکر دارید؟** [setup-vm-docker.fa.md](setup-vm-docker.fa.md) همین پشته را داخل کانتینر اجرا می‌کند — بدون PHP، Composer، Node، Supervisor یا cron روی هاست — و staging و production را کنار هم با استقرار خودکار از CI اضافه می‌کند. یکی از این دو راهنما را انتخاب کنید، نه هر دو را روی یک ماشین: بر سر پورت‌های ۸۰ و ۴۴۳ با هم رقابت می‌کنند.

## ۱. پیش‌نیازها

- اوبونتو 24.04، دسترسی root یا sudo
- پشته نصب‌شده در پایین: PHP 8.3 (+FPM)، MySQL 8.4، Nginx، Composer، Node 20+ (برای بیلد فایل‌ها)، Supervisor
- دامنه اشاره‌شده به سرور — HTTPS الزامی است (هم تلگرام و هم بله وبهوک غیر HTTPS را رد می‌کنند)

## ۲. اصول پایه سرور

ساخت کاربر استقرار و سخت‌سازی فایروال:

```bash
adduser deploy
usermod -aG sudo deploy
rsync --archive --chown=deploy:deploy ~/.ssh /home/deploy
su - deploy
sudo apt update && sudo apt upgrade -y
sudo ufw allow OpenSSH
sudo ufw allow "Nginx Full"
sudo ufw enable
```

نصب پشته:

```bash
sudo apt install -y software-properties-common
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update
sudo apt install -y php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-bcmath \
  php8.3-zip php8.3-gd mysql-server nginx unzip git supervisor
```

Composer:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

Node (فقط برای بیلد فایل‌ها روی سرور لازم است):

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

## ۳. اپلیکیشن

```bash
sudo mkdir -p /var/www/challenges
sudo chown deploy:deploy /var/www/challenges
cd /var/www/challenges
git clone <your-repo-url> .
```

`.env`: از `.env.example` کپی و پر کنید — مرجع کامل هر متغیر در [README](../README.fa.md#متغیرهای-محیطی) است و §۳ راهنمای cPanel متغیرهای ربات/پلتفرم را با جزئیات توضیح می‌دهد (اینجا یکسان‌اند). تفاوت‌های خاص VPS:

```bash
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com        # جایگزین کنید

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=challenges
DB_USERNAME=challenges
DB_PASSWORD=<یک رمز تولید کنید>

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
```

حتی روی VPS هم پروژه به‌موجب طراحی با درایورهای **database** اجرا می‌شود — Redis عمداً وابستگی این کدبیس نیست (README → معماری را ببینید). این‌ها را به Redis «ارتقا» ندهید؛ کد برای درایورهای `database` نوشته و تست شده است.

ساخت دیتابیس و کاربر:

```bash
sudo mysql
```

```sql
CREATE DATABASE challenges CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'challenges'@'127.0.0.1' IDENTIFIED BY '<همان رمز .env>';
GRANT ALL PRIVILEGES ON challenges.* TO 'challenges'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

نصب، بیلد، مایگریشن:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
php artisan key:generate
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear
```

مجوزهای فایل:

```bash
sudo chgrp -R www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache
```

## ۴. بلاک سرور Nginx

`/etc/nginx/sites-available/challenges`:

```nginx
server {
    listen 80;
    server_name yourdomain.com;            # جایگزین کنید
    root /var/www/challenges/public;
    index index.php;

    charset utf-8;
    client_max_body_size 20m;              # آپلود اثبات ویس/عکس

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

فعال‌سازی و تست:

```bash
sudo ln -s /etc/nginx/sites-available/challenges /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## ۵. Supervisor — کارگر صف واقعی

همین نقطه عطف اصلی VPS است. جایی که راهنمای cPanel یک `queue:work --stop-when-empty --max-time=55` محدود به cron اجرا می‌کند (حداکثر حدود یک دقیقه کار در دقیقه)، Supervisor کارگرها را به‌طور پیوسته زنده نگه می‌دارد تا پخش یادآوری‌ها و اعلان‌ها با سرعت کامل تخلیه شود. کد از قبل برای احترام به محدودیت نرخ، ارسال‌ها را یک در ثانیه پخش می‌کند — اینجا هم درست می‌ماند، فقط دیگر انبار نمی‌شود.

`/etc/supervisor/conf.d/challenges-worker.conf`:

```ini
[program:challenges-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/challenges/artisan queue:work --sleep=1 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=deploy
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/challenges/storage/logs/worker.log
stopwaitsecs=3600
```

دو کارگر سقفی عمدی است: پخش ارسال یک پیام در ثانیه به‌ازای هر چت است، پس کارگرهای بیشتر چیزی نمی‌خرند و فقط بر hạn صف دیتابیسی اضافه می‌کنند.

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

`--max-time=3600` هر کارگر را هر ساعت ری‌استارت می‌کند تا حافظه آزاد شود و تغییرات کد بعد از استقرار اعمال گردد؛ بعد از هر استقرار این هم اجرا کنید:

```bash
php artisan queue:restart
```

تا کارگرهای در حال اجرا در مرز job بعدی تمیز خارج شوند و روی کد جدید ری‌استارت شوند.

## ۶. زمان‌بند

یک خط cron همه فرمان‌های زمان‌بندی‌شده را اجرا می‌کند (بستن دوره‌ها، یادآوری‌ها، جدول‌های امتیاز، جاروها — ببینید `routes/console.php`):

```bash
sudo crontab -e
```

```cron
* * * * * deploy /usr/bin/php /var/www/challenges/artisan schedule:run >> /dev/null 2>&1
```

(اگر ترجیح می‌دهید تایمر systemd هم یکسان کار می‌کند؛ اینجا قرارداد cron است.)

## ۷. TLS با Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d yourdomain.com
```

Certbot بلاک سرور را به 443 بازنویسی و تمدید خودکار را نصب می‌کند. ثبت وبهوک (قدم بعدی) **نیازمند** انجام قبلی این مرحله است.

## ۸. ثبت وبهوک‌ها

دقیقاً مثل هاست cPanel — برای راهنمای کامل و هشدار شکست خاموش [setup-cpanel.md §7](setup-cpanel.md#7-registering-the-webhooks) را ببینید. به‌اختصار:

```bash
cd /var/www/challenges

# تلگرام — هر دو راز را هم‌گام نگه می‌دارد و فهرست فرمان‌ها را ثبت می‌کند
php artisan telegram:set-webhook
php artisan telegram:webhook-info
```

بله (فرمان artisan وجود ندارد — چرخه حیات وبهوک فقط تلگرامی است؛ یک بار با tinker ثبت کنید):

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

URL باید HTTPS روی پورت 443 (یا 88) باشد؛ راز مسیر تصادفی تنها سازوکار اصالت بله است.

## ۹. فضای رشدی که این محیط باز می‌کند (اطلاعاتی)

این‌ها **قدم نصب نیستند** — اپ بدون آن‌ها کامل اجرا می‌شود. اما اگر این استقرار روزی از خودش بزرگ‌تر شود، VPS موارد زیر را ممکن می‌کند که هاست اشتراکی هیچ‌کدام را اجازه نمی‌دهد:

- **Redis** برای صف/کش/نشست، به‌علاوه **Horizon** برای دیدپذیری صف — این خودش هم نیازمند یک تصمیم کدی است، چون کدبیس امروز عمداً درایورهای database را هدف گرفته.
- **Reverb / websocket** برای به‌روزرسانی‌های لحظه‌ای مینی‌اپ (در حال حاضر هیچ‌کدام لازم نیست).
- **کارگرهای صف بیشتر** از سقف محدود بالا.
- daemon طولانی‌مدت از هر نوع (اکسپورتر متریک، هشداردهنده dead-man's-switch).
