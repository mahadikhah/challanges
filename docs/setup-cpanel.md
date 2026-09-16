# Deploying to shared hosting (cPanel)

> **فارسی:** این راهنما به فارسی نیز موجود است — [setup-cpanel.fa.md](setup-cpanel.fa.md)

This guide deploys the Challenges platform to a cPanel-style shared host. It reflects how **this specific
project** must be deployed — MySQL-only, no Redis anywhere, cron-driven queue — not generic Laravel
advice. For a VPS deployment (Supervisor, real queue workers) see [setup-vps.md](setup-vps.md).

## 1. Prerequisites

| Requirement | Why |
|---|---|
| **PHP 8.3+** (8.4 fine) | The codebase targets `^8.3` |
| **MySQL 8.4** (or the closest the host offers) | Period/streak queries, row locks, composite uniques |
| **Composer** (via SSH, or the host's installer) | Dependency install |
| **Cron job access** | The scheduler **and** the queue worker both run from cron here |
| **HTTPS on the domain — mandatory** | Telegram and Bale both refuse non-HTTPS webhook URLs; the `telegram:set-webhook` command refuses to register an `http://` URL |
| SSH access (strongly recommended) | Much of this guide is command-line work |

No Redis is required or wanted: queue, cache and sessions all use the `database` driver by design —
see §3.

## 2. Getting the code onto the server

With SSH + git access:

```bash
cd ~/yourdomain.com            # your home/subdomain directory
git clone <your-repo-url> .
```

Without git: clone or download the release archive on your machine, then upload and extract it via
cPanel's File Manager.

**Set the document root to `public/`.** In cPanel: *Domains → your domain → Document Root* →
`/home/USER/yourdomain.com/public`. Laravel must never serve from its root directory; `public/` is the
only folder meant to face the web.

If your host does not let you change the document root, keep the repo in the domain folder and add a
`.htaccess` in the domain root that rewrites everything into `public/`:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(.*)$ public/$1 [L]
</IfModule>
```

Changing the document root is the better option — do that whenever the host allows it.

## 3. `.env` configuration

Copy `.env.example` to `.env` and fill it in. The full variable reference is in the
[README](../README.md#environment-variables). The ones that decide whether this deployment works:

```bash
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com        # replace — must be https

DB_CONNECTION=mysql
DB_HOST=localhost                     # shared hosts expose MySQL locally
DB_PORT=3306
DB_DATABASE=your_db_name              # from cPanel → MySQL Databases
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
```

**Why all three are `database`:** this platform deliberately runs without Redis — the shared host
doesn't provide it, and the code is written for that world (staggered reminder fan-out instead of a
fast broker, unique-constraint idempotency instead of a dedup store). Setting `SESSION_DRIVER=redis`
or similar here doesn't just fail — it breaks the deployment contract the code was built against.
Keep all three on `database`.

Then the bot variables (both are optional — deploy only the platforms you run):

```bash
TELEGRAM_BOT_TOKEN=123456:ABC...              # from @BotFather
TELEGRAM_BOT_USERNAME=your_challenges_bot
TELEGRAM_WEBHOOK_SECRET=<random 32+ chars>    # part of the webhook URL path
TELEGRAM_WEBHOOK_HEADER_SECRET=<random 32+>   # sent back as the Telegram secret header
TELEGRAM_REQUIRED_CHANNEL=@your_channel       # the access-gate channel
MINIAPP_URL="${APP_URL}/miniapp"              # full HTTPS URL; registered in §7, not here

BALE_BOT_TOKEN=...
BALE_BOT_USERNAME=...
BALE_WEBHOOK_SECRET=<random 32+ chars>        # the ONLY Bale webhook secret (no header secret exists)
BALE_REQUIRED_CHANNEL=@your_bale_channel
BALE_PROVIDER_TOKEN=...                       # wallet token from @botfather, NOT the bot token
```

Generate the two random secrets on your machine:

```bash
openssl rand -hex 24    # run twice, one output per secret
```

Finally:

```bash
php artisan key:generate
```

All economy rates, prices, AI limits and the like live in the `settings` database table via the admin
panel — never in env.

## 4. Dependencies & migrations

```bash
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
```

If composer isn't on the host's PATH, use the cPanel SSH path it printed when you enabled it
(typically `/opt/cpanel/composer/bin/composer` or `/usr/local/bin/composer`).

## 5. Frontend assets

The build compiles **two Vite entries** — the admin/website bundle and the Mini App SPA. Shared hosts
usually have no Node.

**Primary path — build locally, upload the output:**

```bash
# on your machine
npm ci
npm run build
```

Then upload the generated `public/build/` directory (the whole folder) to the server's `public/build/`.
That is all the server needs — Vite outputs static files with hashed names plus a manifest Laravel
reads.

**Alternative — build on the server** if the host offers Node 20+ via SSH:

```bash
npm ci
npm run build
```

## 6. Cron entries

Shared hosting has no Supervisor and no daemon privileges, so both the scheduler and the queue worker
run from cron. In cPanel: *Cron Jobs → Add New Cron Job*, or edit the crontab over SSH:

```cron
* * * * * cd /home/USER/yourdomain.com && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/USER/yourdomain.com && /usr/local/bin/php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

Adjust `/usr/local/bin/php` to your host's PHP 8.3+ CLI binary (`which php` over SSH; on cPanel often
`/opt/cpanel/ea-php84/root/usr/bin/php` or similar). `USER`/`yourdomain.com` are placeholders —
replace them.

**What the scheduler runs** (already wired in `routes/console.php`, idempotent by design):
period rollover, reminder minting/dispatch and leaderboard posts every minute; expired Mini App token
pruning, abandoned-payment sweep and proof-media retention daily.

**Why `--stop-when-empty --max-time=55`:** each cron tick starts a worker, lets it drain the queue,
and kills it before the next tick starts — no overlap, no daemon. This **bounds throughput to roughly
one minute of work per minute**. That is expected here, not a deployment mistake: the code staggers
reminder and announcement fan-out one send per second precisely because this is the deployment shape
it was designed for. If the queue ever grows faster than one worker drains it, the answer is the VPS
guide, not more cron lines.

## 7. Registering the webhooks

### Telegram

One command registers the webhook with **both** of its secrets kept in step:

```bash
php artisan telegram:set-webhook
```

It reads `TELEGRAM_WEBHOOK_SECRET` (path) and `TELEGRAM_WEBHOOK_HEADER_SECRET` (header) from `.env`,
refuses to register an `http://` URL, and asks Telegram to deliver only the update kinds the router
handles. Add `--drop-pending-updates` on a first deploy to discard anything queued before now.

It also registers the bot's **command menu** — the list under the menu button — once per supported
language, plus an unscoped set that covers every other client language. Re-run it after changing the
`bot.commands.*` lines in `lang/`, or the menu keeps the old wording.

Verify with:

```bash
php artisan telegram:webhook-info
```

Registering by hand through BotFather or a raw `setWebhook` call with only one of the two secrets is
the classic silent failure: the endpoint demands both, so every update 404s *before reaching anything
that logs*, and the bot just goes quiet. Always use the command.

### Telegram Mini App

The webhook above is what makes the *bot* work. The Mini App is registered separately, and it needs
one more step that is easy to miss because nothing fails loudly without it:

```bash
php artisan telegram:set-menu-button
```

That puts the Mini App behind the **menu button** — the permanent tap target beside the message field.
Without it there is no way into the app from the chat. Registering a Web App with BotFather
(`/newapp`) is **not** a substitute: it creates a *named* app reachable at `t.me/<bot>/<app>`, a URL
nobody types, and adds nothing to the conversation. If you would rather do it by hand, the equivalent
is **BotFather → your bot → Bot Settings → Menu Button**, and the URL to enter is the **full HTTPS
URL**, e.g. `https://your-domain.com/miniapp` — `/miniapp` on its own is a path, not a URL.

The command reads `MINIAPP_URL`, refuses an `http://` URL before sending anything (Telegram rejects a
non-HTTPS Web App button), and then reads the button back and compares it against what you configured.
That read-back matters: `setChatMenuButton` answering `true` only means Telegram accepted the call, and
BotFather's UI shows what you *typed* rather than what Telegram *stored*.

> **`MINIAPP_URL` must be a full HTTPS URL.** In this host's `.env`,
> `MINIAPP_URL="${APP_URL}/miniapp"` interpolates correctly — Laravel's dotenv expands `${…}`. That is
> *not* true of the Docker deploy, whose `env_file` passes the string through literally
> (`deploy/production.env.example` says so). On a cPanel host either form works; on the VM, write it
> out in full.

Verify the whole path with:

```bash
php artisan telegram:miniapp-diagnose
```

It reports the token's length (never its value), **which bot the token belongs to**, what menu button
Telegram actually has stored, whether `MINIAPP_URL` answers, and then self-tests `initData`
verification. The bot-name line is the one that catches the failure nothing else can: a token
belonging to a *different* bot verifies perfectly against itself and fails every real user's
`initData`, in exactly the way a forged one does.

Finally, note that Telegram only hands `initData` to an **HTTPS** page. A plain-HTTP Mini App boots to
"open me from Telegram" while looking perfectly reachable — which is why the URL is checked twice.

### Bale

Bale has **no header-secret mechanism** — `setWebhook` accepts only a URL — so the random
`BALE_WEBHOOK_SECRET` path segment is the entire authenticity mechanism. There is no artisan command
for Bale's webhook (webhook lifecycle is Telegram-only by design); register it once with tinker:

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

Constraints verified against docs.bale.ai: the URL must be HTTPS on port 443 (or 88). The endpoint
answers 404 on a wrong path secret — a probe cannot tell a wrong secret from an unrouted path.

## 8. File permissions

```bash
chmod -R ug+w storage bootstrap/cache
```

Both directories must be writable by the web-server user. On cPanel with suPHP/LSAPI the file owner
usually suffices; if you see "permission denied" in `storage/logs/laravel.log`'s absence, that is why.

## 9. cPanel gotchas

- **Disabled PHP functions**: some hosts disable `proc_open`/`putenv`, which Composer and some artisan
  commands need. Check *MultiPHP INI Editor → disable_functions*; remove those two at minimum for the
  CLI binary used in cron.
- **`max_execution_time`** interacts with `--max-time=55`: the queue worker caps itself at 55 seconds,
  but if PHP's `max_execution_time` is below ~60 the process dies mid-job instead. Jobs are retried,
  but set `max_execution_time=60` (or higher) for the CLI PHP to be safe. `set_time_limit` matters
  less for CLI, but hosts that force it via a hard limit exist.
- **Memory**: `composer install` wants ~1G; if it fails, run it with
  `php -d memory_limit=1G /usr/local/bin/composer install ...`.
- **OPcache**: enable it for the web PHP; the cron PHP has its own INI — check with
  `php -i | grep php.ini` over SSH, not what cPanel shows for the web.
- **`public/.htaccess`**: ships with the repo (Laravel standard). If URLs 404 except the homepage,
  `mod_rewrite` is off for the account — ask the host.
- **MySQL version**: if the host only offers MySQL 5.7/8.0, the app will likely run but 8.4 is what
  the project tests against; prefer a host with 8.x.

## 10. Troubleshooting

| Symptom | Likely cause → fix |
|---|---|
| Bot silent after deploy, nothing in logs | Webhook registered with one secret or wrong URL → `php artisan telegram:webhook-info`, re-run `php artisan telegram:set-webhook` |
| Bot silent **and** logs show nothing **and** webhook info looks right | Cron not running → check *Cron Jobs* mail output; run `php artisan schedule:run` by hand once |
| Bot replies but reminders/announcements lag minutes or never arrive | Queue worker cron not running, or dying instantly → run `php artisan queue:work --stop-when-empty --max-time=55` by hand; check `php -v` is 8.3+ |
| "could not find driver" (pdo_mysql) | Wrong CLI PHP binary in cron → point the crontab at the ea-php83/84 binary |
| `ViteException: Unable to locate file in Vite manifest` | `public/build/` missing or stale → rebuild locally and re-upload (README §running-locally) |
| Telegram refused webhook registration | `APP_URL` not `https://` — fix APP_URL and `php artisan config:clear` |
| Payments work on Telegram but Bale shop says disabled | `BALE_PROVIDER_TOKEN` empty — Bale Pay refuses to run without the wallet token |
| 500 on every page, blank logs | `storage/` not writable → §8 |
| Old env values stick after editing `.env` | Cached config → `php artisan config:clear` (and `php artisan optimize:clear` on redeploys) |
| Mini App: "could not verify your Telegram identity" | Read `storage/logs/laravel.log` for **`Mini App authentication was rejected`** — that line names the reason. Nothing in the log at all means the request never reached the controller: a routing/docroot problem, not an identity one |
| Mini App: "we could not reach the server" | The `/auth` call got no usable answer — not an identity failure. Check for a 500 in `storage/logs/laravel.log` |
| Mini App: "open me from Telegram" | `MINIAPP_URL` is not HTTPS, or the app was opened in a plain browser — Telegram only hands `initData` to an HTTPS page inside Telegram |
| Mini App: no menu button, or tapping it opens nothing | `php artisan telegram:set-menu-button`, then `php artisan telegram:miniapp-diagnose` |
| Mini App fails for *every* user at once | The token belongs to a different bot → `php artisan telegram:miniapp-diagnose` names the bot the configured token actually belongs to |

### Decoding `Mini App authentication was rejected`

The Mini App's `/auth` endpoint answers one uniform 401 on purpose — a caller who could tell "tampered"
from "outdated" learns which of their forgeries is closest to working. The reason is in the log line
instead, which only the operator can read. Open `storage/logs/laravel.log` in cPanel's *File Manager*
and search for the message above:

| The log line says | Cause | Fix |
|---|---|---|
| `The initData hash does not match its contents.` | The app is opened under a **different bot** than the token on this host | `php artisan telegram:miniapp-diagnose` names the bot; point the menu button at this host's bot |
| `The initData cannot be verified: no bot token is configured.` | Token empty *at runtime* — cached config, or the wrong `.env` | `php artisan config:clear`; confirm the deployed `.env` |
| `The initData is Ns old, past the Ms window.` | Server clock skew, or a small `initdata_max_age_seconds` | Fix the host clock; check the setting in the admin panel |
| `The initData is not a well-formed payload: …` | Mangled payload — should not occur from a real Telegram client | Investigate the client; treat as a bug |
| **Nothing at all** | The request never reached the controller | Routing/docroot — the app never ran. Not an identity problem |
| A **stack trace** instead of that line | A 500 — e.g. Sanctum's `personal_access_tokens` table missing | `php artisan migrate --force` |

The last two rows are why this comes first: neither is an identity problem, and no amount of token
checking finds them. Rows 2–4 assume the code is *reached*; the last two say it is not.

On every code update: `git pull` (or re-upload) → `composer install --no-dev --optimize-autoloader` →
`php artisan migrate --force` → rebuild/re-upload `public/build` → `php artisan optimize:clear`.
