# استقرار روی VM فقط-داکر

> **English:** this guide is also available in English — [setup-vm-docker.md](setup-vm-docker.md)

این راهنما پلتفرم چالش‌ها را روی یک VM اجرا می‌کند که **تنها چیز نصب‌شده روی آن داکر است** — بدون PHP، بدون Composer، بدون Node، بدون Nginx، بدون Supervisor، بدون cron. همه‌چیز داخل کانتینر اجرا می‌شود.

این جایگزین داکرمحور [setup-vps.fa.md](setup-vps.fa.md) است که همان پشته را دستی روی خود سرور نصب می‌کند. یکی را انتخاب کنید؛ قرار نیست هر دو روی یک ماشین اجرا شوند، چون هر دو پورت‌های ۸۰ و ۴۴۳ را می‌خواهند.

آنچه به دست می‌آورید: staging و production کنار هم روی یک VM، هرکدام یک پشته جدا، با یک لبه TLS مشترک. استقرار به‌صورت خودکار از CI انجام می‌شود، یا دستی با `deploy/deploy.sh`.

## ۱. پیش‌نیازها

- هر VM لینوکسی با **Docker Engine 20.10+** و **git**. هم Compose نسخه ۱ (`docker-compose`) و هم نسخه ۲ (`docker compose`) پشتیبانی می‌شوند — اسکریپت‌ها خودشان تشخیص می‌دهند کدام نصب است.
- **دو دامنه** اشاره‌شده به IP عمومی VM، یکی برای هر محیط (مثلاً `challenges.example.com` و `staging.challenges.example.com`). HTTPS الزامی است: هم تلگرام و هم بله وبهوک غیر HTTPS را رد می‌کنند.
- پورت‌های **۸۰** و **۴۴۳** باز باشند. پورت ۸۰ لازم است حتی با وجود اینکه اپلیکیشن فقط HTTPS است — چالش گواهی ACME و ریدایرکت HTTP→HTTPS هر دو از آن استفاده می‌کنند.
- دسترسی خواندن به پکیج GHCR، و یک توکن شخصی گیت‌هاب با `read:packages` اگر پکیج‌ها خصوصی‌اند.
- حدود ۴ گیگابایت RAM برای هر دو محیط به‌همراه MySQL روی یک VM کافی است.

همین. اگر دیدید دارید PHP نصب می‌کنید، راهنمای اشتباهی را باز کرده‌اید.

## ۲. آماده‌سازی اولیه VM

مخزن را کلون کنید. فقط فایل‌های compose، Caddyfile و اسکریپت‌ها از آن استفاده می‌شوند — خود اپلیکیشن به‌صورت ایمیج آماده می‌رسد، پس این درخت کاری «پیکربندی» است، نه «کد».

```bash
git clone https://github.com/mahadikhah/challanges.git /opt/challenges
cd /opt/challenges
```

اگر پکیج‌های GHCR خصوصی‌اند، یک‌بار وارد شوید:

```bash
echo "$GITHUB_TOKEN" | docker login ghcr.io -u <your-github-username> --password-stdin
```

شبکه لبه مشترک را بسازید. هر دو پشته اپلیکیشن و Caddy به آن می‌پیوندند، و عمداً دستی ساخته می‌شود تا حذف هیچ پشته‌ای نتواند آن را از بین ببرد:

```bash
docker network create challenges-edge
```

حالا پیکربندی هر محیط. این فایل‌ها توکن‌های ربات و رمز دیتابیس را نگه می‌دارند و در gitignore هستند — فقط روی VM وجود دارند:

```bash
cp deploy/staging.env.example    deploy/staging.env
cp deploy/production.env.example deploy/production.env
```

هر دو را پر کنید. توضیح همه مقادیر داخل خود قالب آمده؛ این‌ها آن‌هایی‌اند که بیشتر دردسر می‌سازند:

| متغیر | چرا مهم است |
|---|---|
| `APP_KEY` | با `docker run --rm ghcr.io/mahadikhah/challanges/app:latest php artisan key:generate --show` بسازید. **تغییر بعدی آن همه نشست‌ها و همه ستون‌های رمزنگاری‌شده را بی‌اعتبار می‌کند.** |
| `APP_URL` | باید URL عمومی واقعی با `https://` باشد. `telegram:set-webhook` آدرس وبهوک را از آن می‌سازد و هر چیزی جز HTTPS را رد می‌کند. |
| `DB_HOST` | باید `mysql` باشد — نام سرویس در compose. مقدار `127.0.0.1` داخل کانتینر اپ، یعنی *خودِ کانتینر اپ*. |
| `DEPLOY_DOMAIN` | چیزی که `deploy.sh` گزارش می‌دهد و Caddy برایش گواهی می‌گیرد. باید از قبل به این VM اشاره کند. |
| `EDGE_ALIAS` | نامی که Caddy به آن پراکسی می‌کند. باید با هدف `reverse_proxy` در Caddyfile یکی باشد — یا هر دو را تغییر دهید یا هیچ‌کدام. |
| `TELEGRAM_BOT_TOKEN` | **برای staging از ربات دیگری استفاده کنید.** اگر دو استقرار یک ربات را به دو وبهوک وصل کنند، هرکدام که دیرتر ثبت شود بی‌سروصدا برنده می‌شود و دیگری بدون هیچ خطایی ساکت می‌ماند. |

> **`env_file` داکر متغیرها را بسط نمی‌دهد.** برخلاف `.env` لاراول، نوشتن `MINIAPP_URL=${APP_URL}/miniapp` در اینجا همان رشته خام `${APP_URL}/miniapp` را به PHP تحویل می‌دهد. هر مقدار را کامل بنویسید.

## ۳. لبه TLS

یک Caddy برای کل VM، که یک‌بار بالا می‌آید و دیگر کاری با آن ندارید. خودش گواهی Let's Encrypt را می‌گیرد و تمدید می‌کند — و دلیل انتخابش به‌جای nginx + certbot دقیقاً همین است، چون تمدید certbot به cron وابسته است و یک VM فقط-داکر cron ندارد.

```bash
cd deploy/edge
cp edge.env.example edge.env
# مقادیر STAGING_DOMAIN و PRODUCTION_DOMAIN و ACME_EMAIL را تنظیم کنید.
docker compose -f compose.edge.yml --env-file edge.env up -d
cd ../..
```

لبه در تمام استقرارهای اپلیکیشن بالا می‌ماند. `deploy.sh` عمداً هرگز به آن دست نمی‌زند: یک استقرار ناموفق نباید بتواند دامنه‌ها یا گواهی‌هایشان را هم با خودش پایین بکشد.

گواهی‌ها در والیوم `caddy-data` ذخیره می‌شوند. **این والیوم را سرسری حذف نکنید** — درخواست دوباره گواهی‌ها از صفر، همان راهی است که بازسازی یک VM را به محدودیت نرخ Let's Encrypt (۵ بار برای هر دامنه در هفته) می‌رساند.

تا وقتی DNS واقعاً به این VM اشاره نکند، Caddy موفق به گرفتن گواهی نمی‌شود. با `docker compose -f compose.edge.yml logs -f caddy` وضعیت را ببینید.

## ۴. اولین استقرار

```bash
deploy/deploy.sh staging
deploy/deploy.sh production
```

همین. اسکریپت ایمیج‌ها را می‌کشد، مایگریشن‌ها را *پیش از* جابه‌جایی ترافیک روی ایمیج جدید اجرا می‌کند، کانتینرها را بالا می‌آورد و `/up` را تا پاسخ‌دادن پشته می‌پاید.

اجرای اول چند دقیقه طول می‌کشد: MySQL دارد دایرکتوری داده‌اش را می‌سازد و مرحله مایگریشن در همان حین تلاش را تکرار می‌کند. `mysqladmin ping` خیلی زودتر از آنکه MySQL اتصال اپلیکیشن را بپذیرد جواب می‌دهد، و حلقه تکرار دقیقاً همین فاصله را پوشش می‌دهد.

هر محیط یک پروژه compose جداست (`challenges-staging` و `challenges-production`) با کانتینرها، MySQL و والیوم‌های مخصوص خودش. تنها چیز مشترکشان شبکه لبه و کرنل VM است.

هر پشته پنج سرویس دارد:

| سرویس | کارش چیست |
|---|---|
| `web` | nginx، که `public/` را سرو می‌کند و PHP را به `app` پراکسی می‌دهد. **هیچ پورتی روی هاست منتشر نمی‌کند** — فقط Caddy به آن می‌رسد. |
| `app` | php-fpm. |
| `scheduler` | `schedule:work`، جایگزین خط cron هاست از [setup-vps.fa.md §۶](setup-vps.fa.md). |
| `queue` | `queue:work`، جایگزین Supervisor از [setup-vps.fa.md §۵](setup-vps.fa.md). تعدادش با `QUEUE_WORKERS` تنظیم می‌شود. |
| `mysql` | MySQL 8.4 روی یک والیوم نام‌دار. |

## ۵. استقرار روزمره و بازگشت

پوش به `master` به‌صورت خودکار staging را مستقر می‌کند. پوش یک تگ `v*` محیط production را. هر دو از همان `deploy.sh` روی SSH عبور می‌کنند، پس CI و آدم دقیقاً یک کار را انجام می‌دهند.

```bash
deploy/deploy.sh staging                # آخرین نسخه، با git pull
deploy/deploy.sh production v1.4.0      # یک تگ مشخص
deploy/deploy.sh production --no-pull   # استقرار دوباره بدون دست‌زدن به git
deploy/deploy.sh staging --rollback     # بازگشت به آخرین تگ سالم
```

اگر بررسی سلامت شکست بخورد، اسکریپت خودش به آخرین تگ سالم برمی‌گردد، ۴۰ خط آخر لاگ `app` و `web` را چاپ می‌کند و با کد خطا خارج می‌شود. تگ ناموفق در `deploy/<env>.failed` و تگ سالم در `deploy/<env>.last-good` ثبت می‌شود.

> **بازگشت، کد را برمی‌گرداند نه اسکیما را.** مایگریشن‌ها پیش از جابه‌جایی ترافیک اجرا می‌شوند، پس بازگشت، دیتابیس را جلو رفته باقی می‌گذارد. این برای مایگریشن‌های افزایشی — جدول جدید، ستون nullable جدید — مشکلی ندارد، اما برای ستون حذف‌شده یا تغییرنام‌یافته **خطرناک است**، چون کد قدیمی‌تر سراغ چیزی می‌رود که دیگر وجود ندارد. اگر نسخه‌ای مایگریشن مخرب دارد، اول یک dump بگیرید (§۷) و به‌جای بازگشت، آن را بازیابی کنید.

کارهای روزمره:

```bash
# لاگ‌ها (به‌خاطر LOG_STACK=stderr، لاگ اپلیکیشن هم اینجاست)
docker compose -p challenges-production -f deploy/compose.app.yml logs -f app

# وضعیت
docker compose -p challenges-production -f deploy/compose.app.yml ps

# اجرای artisan روی پشته در حال اجرا
docker compose -p challenges-production -f deploy/compose.app.yml exec app php artisan about
```

## ۶. ثبت وبهوک‌ها

**پس از اولین استقرار هر محیط الزامی است** — تا وقتی این اجرا نشود، ربات هیچ چیزی دریافت نمی‌کند. برای هر محیط یک‌بار انجامش دهید، و هر بار که `APP_URL` یا یکی از رازهای وبهوک تغییر کرد دوباره.

شرح کامل، به‌همراه هشدار شکست خاموش، در [setup-cpanel.fa.md §۷](setup-cpanel.fa.md) است. اینجا داخل کانتینر اجرا می‌شود:

```bash
cd /opt/challenges
C="docker compose -p challenges-production -f deploy/compose.app.yml"

# تلگرام — این فرمان هر دو راز را هم‌گام نگه می‌دارد
$C exec app php artisan telegram:set-webhook
$C exec app php artisan telegram:webhook-info
```

بله فرمان artisan ندارد — چرخه وبهوک فقط برای تلگرام پیاده شده، پس یک‌بار با tinker ثبتش کنید:

```bash
$C exec app php artisan tinker --execute='
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

آدرس باید HTTPS روی پورت ۴۴۳ (یا ۸۸) باشد؛ راز تصادفی مسیر، تنها سازوکار احراز اصالت بله است.

اگر `telegram:webhook-info` آدرسی نشان داد که نمی‌شناسید، یعنی استقرار دیگری همان ربات را تصاحب کرده است. این همان تداخل ربات staging/production است که در §۲ هشدار داده شد.

## ۷. پشتیبان‌گیری

والیوم `mysql-data` تنها چیز جبران‌ناپذیر این پشته است — ایمیج‌ها دوباره ساختنی‌اند و فایل‌های env کوتاه. اگر شرکت‌کننده‌ها عکس آپلود می‌کنند، رسانه اثبات در `storage-app` هم مهم است.

```bash
cd /opt/challenges
C="docker compose -p challenges-production -f deploy/compose.app.yml"

# دیتابیس
$C exec -T mysql mysqldump -u root -p"$DB_ROOT_PASSWORD" --single-transaction challenges \
    | gzip > "challenges-$(date +%F).sql.gz"

# رسانه اثبات
docker run --rm -v challenges-production_storage-app:/data -v "$PWD:/backup" \
    busybox tar czf /backup/storage-$(date +%F).tar.gz -C /data .
```

بازیابی:

```bash
gunzip < challenges-2026-01-15.sql.gz | $C exec -T mysql mysql -u root -p"$DB_ROOT_PASSWORD" challenges
```

پیش از هر نسخه‌ای که مایگریشن مخرب دارد حتماً dump بگیرید (§۵). پشتیبان‌ها را از VM بیرون ببرید — پشتیبانی که فقط روی همان ماشینی است که قرار بوده از آن محافظت کند، پشتیبان نیست.

## ۸. توسعه روی VM

برای VMای که به‌جای مقصد استقرار، سرور توسعه است. این حالت Laravel Sail را از ایمیج منتشرشده اجرا می‌کند، پس روی ماشین بدون PHP هم کار می‌کند.

مسئله مرغ و تخم‌مرغ: خودِ `vendor/bin/sail` را Composer نصب می‌کند، پس روی ماشین فقط-داکر تا وقتی Composer اجرا نشده وجود ندارد — و Composer فقط داخل همان کانتینری اجرا می‌شود که `sail` قرار بود بالا بیاورد. `deploy/dev.sh` راه ورود است.

```bash
git clone https://github.com/mahadikhah/challanges.git ~/challenges
cd ~/challenges
deploy/dev.sh setup     # ایمیج را می‌کشد، وابستگی‌ها را نصب می‌کند، کلید می‌سازد، مایگریت می‌کند، فایل‌ها را بیلد می‌کند
```

سپس:

```bash
deploy/dev.sh up                # شروع
deploy/dev.sh artisan migrate   # هر فرمان artisan
deploy/dev.sh composer require … 
deploy/dev.sh npm run dev       # سرور توسعه Vite
deploy/dev.sh test              # دروازه کامل کیفیت
deploy/dev.sh shell             # شل تعاملی
deploy/dev.sh down
```

بعد از اجرای `setup`، فایل `vendor/bin/sail` وجود دارد و کار می‌کند. با این حال `dev.sh` را ترجیح دهید: فایل `compose.yaml` ریشه ایمیج PHP خود را از `vendor/laravel/sail/runtimes/8.5` **می‌سازد**، در حالی که `dev.sh` از همان ایمیجی استفاده می‌کند که CI ساخته است.

این پشته توسعه، پورت `APP_PORT` (پیش‌فرض ۸۰) را مستقیم روی هاست می‌بندد، پس **آن را روی همان VMای که لبه production را دارد اجرا نکنید** — روی پورت ۸۰ تداخل پیدا می‌کنند.

## ۹. تفاوت‌ها با راهنمای VPS

اگر [setup-vps.fa.md](setup-vps.fa.md) را می‌شناسید، تفاوت‌ها این‌هاست:

| VPS | اینجا |
|---|---|
| `supervisorctl` برای کارگرهای صف | سرویس `queue`، با `--scale queue=N` از طریق `QUEUE_WORKERS` |
| خط crontab برای زمان‌بند | سرویس `scheduler` با `schedule:work` |
| certbot + cron تمدید | Caddy، خودکار |
| `git pull && composer install && npm run build` روی هاست | ایمیج آماده از GHCR، ساخته‌شده در CI |
| `storage/logs/laravel.log` | `docker compose logs` (با `LOG_STACK=stderr`) |
| PHP-FPM روی سوکت یونیکس | php-fpm روی پورت ۹۰۰۰ در شبکه خصوصی پشته |

بدون تغییر: MySQL 8.4، درایورهای `database` برای صف/کش/نشست (در هیچ‌کدام Redis نیست)، سقف دو کارگر، و روند ثبت وبهوک.
