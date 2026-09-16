# Deploying to an Ubuntu VPS

> **فارسی:** این راهنما به فارسی نیز موجود است — [setup-vps.fa.md](setup-vps.fa.md)

This guide deploys the Challenges platform to a fresh Ubuntu 24.04 VPS with Nginx, PHP-FPM, MySQL and
Supervisor — a **real long-running queue worker**, which is the main thing this environment offers over
the cron-bounded shared-hosting deployment in [setup-cpanel.md](setup-cpanel.md).

> **Only have Docker on the box?** [setup-vm-docker.md](setup-vm-docker.md) runs the same stack in
> containers — no PHP, Composer, Node, Supervisor or cron on the host — and adds staging/production
> side by side with CI deploys. Use one guide or the other, not both on one machine: they compete for
> ports 80 and 443.

## 1. Prerequisites

- Ubuntu 24.04, root or sudo access
- The stack installed below: PHP 8.3 (+FPM), MySQL 8.4, Nginx, Composer, Node 20+ (for asset builds),
  Supervisor
- A domain pointed at the server — HTTPS is mandatory (both Telegram and Bale refuse non-HTTPS
  webhooks)

## 2. Server basics

Create a deploy user and harden the firewall:

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

Install the stack:

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

Node (only needed for building assets on the server):

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

## 3. The application

```bash
sudo mkdir -p /var/www/challenges
sudo chown deploy:deploy /var/www/challenges
cd /var/www/challenges
git clone <your-repo-url> .
```

`.env`: copy from `.env.example` and fill in — the full per-variable reference is in the
[README](../README.md#environment-variables), and the cPanel guide's §3 explains the bot/platform
variables in detail (they are identical here). The VPS-specific differences:

```bash
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com        # replace

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=challenges
DB_USERNAME=challenges
DB_PASSWORD=<generate one>

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
```

Even on a VPS the project runs **database** queue/cache/session by design — Redis is deliberately not
a dependency of this codebase (see README → Architecture). Don't "upgrade" these to Redis; the code
was written and tested against the `database` drivers.

Create the database and user:

```bash
sudo mysql
```

```sql
CREATE DATABASE challenges CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'challenges'@'127.0.0.1' IDENTIFIED BY '<same password as .env>';
GRANT ALL PRIVILEGES ON challenges.* TO 'challenges'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

Install, build, migrate:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
php artisan key:generate
npm ci && npm run build
php artisan migrate --force
php artisan optimize:clear
```

File permissions:

```bash
sudo chgrp -R www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache
```

## 4. Nginx server block

`/etc/nginx/sites-available/challenges`:

```nginx
server {
    listen 80;
    server_name yourdomain.com;            # replace
    root /var/www/challenges/public;
    index index.php;

    charset utf-8;
    client_max_body_size 20m;              # voice/photo proof uploads

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

Enable and test:

```bash
sudo ln -s /etc/nginx/sites-available/challenges /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

## 5. Supervisor — the real queue worker

This is the VPS's whole point. Where the cPanel guide runs a cron-bounded
`queue:work --stop-when-empty --max-time=55` (at most ~one minute of work per minute), Supervisor
keeps workers alive continuously, so reminder and announcement fan-out drains at full speed. The code
already staggers sends one per second out of rate-limit respect — that stays correct here, it simply
never backs up.

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

Two workers is a deliberate ceiling: the send stagger is one message per second per chat, so more
workers buy little and only add database-queue contention.

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

`--max-time=3600` restarts each worker hourly, which releases memory and picks up code changes after a
deploy; after every deploy also run:

```bash
php artisan queue:restart
```

so in-flight workers exit cleanly at the next job boundary and restart on the new code.

## 6. Scheduler

One cron line drives every scheduled command (rollover, reminders, leaderboards, sweeps — see
`routes/console.php`):

```bash
sudo crontab -e
```

```cron
* * * * * deploy /usr/bin/php /var/www/challenges/artisan schedule:run >> /dev/null 2>&1
```

(A systemd timer works equally well if you prefer; cron is the convention here.)

## 7. TLS via Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d yourdomain.com
```

Certbot rewrites the server block to 443 and installs auto-renewal. Webhook registration (next step)
**requires** this to be done first.

## 8. Registering the webhooks

Exactly as on the cPanel host — see [setup-cpanel.md §7](setup-cpanel.md#7-registering-the-webhooks)
for the full walkthrough and the silent-failure warning. In short:

```bash
cd /var/www/challenges

# Telegram — keeps both secrets in step, and registers the command menu
php artisan telegram:set-webhook
php artisan telegram:webhook-info

# Mini App — the menu button is the only way into the app from inside the chat
php artisan telegram:set-menu-button
```

Bale (no artisan command — webhook lifecycle is Telegram-only; register once via tinker):

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

The URL must be HTTPS on port 443 (or 88); the random path secret is Bale's only authenticity
mechanism.

## 9. Headroom this environment unlocks (informational)

These are **not** setup steps — the app runs fully without them. But if this deployment ever outgrows
itself, a VPS makes the following possible, none of which shared hosting allows:

- **Redis** for queue/cache/sessions, plus **Horizon** for queue visibility — would require a code
  decision too, since the codebase deliberately targets the database drivers today.
- **Reverb / websockets** for realtime Mini App updates (currently none are needed).
- **More queue workers** than the modest ceiling set above.
- Long-running daemons of any kind (metrics exporters, dead-man's-switch alerters).
