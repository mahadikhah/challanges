# Deploying to a Docker-only VM

> **فارسی:** این راهنما به فارسی نیز موجود است — [setup-vm-docker.fa.md](setup-vm-docker.fa.md)

This guide runs the Challenges platform on a VM where **Docker is the only thing installed** — no PHP,
no Composer, no Node, no Nginx, no Supervisor, no cron. Everything below runs in a container.

It is the Docker-native alternative to [setup-vps.md](setup-vps.md), which installs the same stack onto
the host by hand. Pick one; they are not meant to be combined on a single machine, since both want
ports 80 and 443.

What you get: staging and production side by side on one VM, each a separate stack, sharing one TLS
edge. Deploys come from CI automatically, or from `deploy/deploy.sh` by hand.

## 1. Prerequisites

- Any Linux VM with **Docker Engine 20.10+** and **git**. Compose v1 (`docker-compose`) and v2
  (`docker compose`) are both supported — the scripts detect which one is present.
- **Two domains** pointed at the VM's public IP, one per environment (e.g. `challenges.example.com`
  and `staging.challenges.example.com`). HTTPS is mandatory: Telegram and Bale both refuse non-HTTPS
  webhooks.
- Ports **80** and **443** open. Port 80 is required even though the app is HTTPS-only — the ACME
  certificate challenge and the HTTP→HTTPS redirect both use it.
- Read access to the GHCR package, and a GitHub personal access token with `read:packages` if the
  packages are private.
- ~4 GB RAM is comfortable for both environments plus MySQL on one VM.

Nothing else. If you find yourself installing PHP, you are on the wrong guide.

## 2. First-time VM setup

Clone the repo. Only the compose files, the Caddyfile and the scripts are used from it — the
application itself arrives as a prebuilt image, so the working tree is configuration, not code.

```bash
git clone https://github.com/mahadikhah/challanges.git /opt/challenges
cd /opt/challenges
```

If the GHCR packages are private, log in once:

```bash
echo "$GITHUB_TOKEN" | docker login ghcr.io -u <your-github-username> --password-stdin
```

Create the shared edge network. Both app stacks and Caddy join it, and it is deliberately created by
hand so that tearing down any one stack cannot delete it:

```bash
docker network create challenges-edge
```

Now the per-environment config. These files hold the bot tokens and database passwords and are
gitignored — they exist only on the VM:

```bash
cp deploy/staging.env.example    deploy/staging.env
cp deploy/production.env.example deploy/production.env
```

Fill each one in. Every value is commented in the template; these are the ones that bite:

| Variable | Why it matters |
|---|---|
| `APP_KEY` | Generate with `docker run --rm ghcr.io/mahadikhah/challanges/app:latest php artisan key:generate --show`. **Changing it later invalidates every session and every encrypted column.** |
| `APP_URL` | Must be the real public `https://` URL. `telegram:set-webhook` builds the webhook URL from it and refuses anything that is not HTTPS. |
| `DB_HOST` | Must be `mysql` — the compose service name. `127.0.0.1` inside the app container is the *app container*. |
| `DEPLOY_DOMAIN` | What `deploy.sh` reports and Caddy certifies. Must already resolve to this VM. |
| `EDGE_ALIAS` | The name Caddy proxies to. Must match the `reverse_proxy` target in the Caddyfile — change both or neither. |
| `TELEGRAM_BOT_TOKEN` | **Use a different bot for staging.** Two deployments pointing one bot at two webhooks means whichever registered last silently wins, and the other goes quiet with no error. |

> **Docker's `env_file` does not expand variables.** Unlike Laravel's `.env`, writing
> `MINIAPP_URL=${APP_URL}/miniapp` here delivers the literal string `${APP_URL}/miniapp` to PHP.
> Write every value out in full.

## 3. The TLS edge

One Caddy for the whole VM, brought up once and left alone. It obtains and renews Let's Encrypt
certificates by itself — which is the reason it is here rather than nginx + certbot, since certbot's
renewal depends on cron, and a Docker-only VM has none.

```bash
cd deploy/edge
cp edge.env.example edge.env
# Set STAGING_DOMAIN, PRODUCTION_DOMAIN and ACME_EMAIL.
docker compose -f compose.edge.yml --env-file edge.env up -d
cd ../..
```

The edge stays up across every application deploy. `deploy.sh` never touches it, deliberately: a
failed deploy must not be able to take the domains or their certificates offline with it.

Certificates live in the `caddy-data` volume. **Do not delete that volume casually** — re-requesting
certificates from scratch is how a VM rebuild walks into Let's Encrypt's rate limit of 5 per domain
per week.

Caddy will fail to obtain a certificate until DNS actually resolves to this VM. Watch it with
`docker compose -f compose.edge.yml logs -f caddy`.

## 4. First deploy

```bash
deploy/deploy.sh staging
deploy/deploy.sh production
```

That is the whole thing. The script pulls the images, runs migrations on the new image *before*
moving traffic, starts the containers, and polls `/up` until the stack answers.

First run takes a few minutes: MySQL initialises its data directory, and the migration step retries
while that happens. `mysqladmin ping` starts answering well before MySQL accepts application
connections, which is exactly the gap the retry loop covers.

Each environment is a separate compose project (`challenges-staging`, `challenges-production`) with
its own containers, its own MySQL, and its own volumes. They share only the edge network and the VM's
kernel.

Each stack runs five services:

| Service | What it does |
|---|---|
| `web` | nginx, serves `public/` and proxies PHP to `app`. Publishes **no host ports** — only Caddy reaches it. |
| `app` | php-fpm. |
| `scheduler` | `schedule:work`, replacing the host cron line from [setup-vps.md §6](setup-vps.md#6-scheduler). |
| `queue` | `queue:work`, replacing Supervisor from [setup-vps.md §5](setup-vps.md#5-supervisor--the-real-queue-worker). Scaled by `QUEUE_WORKERS`. |
| `mysql` | MySQL 8.4, on a named volume. |

## 5. Routine deploys and rollback

Pushing to `master` deploys staging automatically. Pushing a `v*` tag deploys production. Both go
through the same `deploy.sh` over SSH, so CI and a human do identical things.

```bash
deploy/deploy.sh staging                # latest, git pull first
deploy/deploy.sh production v1.4.0      # a specific tag
deploy/deploy.sh production --no-pull   # redeploy without touching git
deploy/deploy.sh staging --rollback     # back to the last known-good tag
```

If the health check fails, the script rolls back to the last known-good tag on its own, prints the
last 40 lines of the `app` and `web` logs, and exits non-zero. The failed tag is recorded in
`deploy/<env>.failed`, the working one in `deploy/<env>.last-good`.

> **Rollback returns the code, not the schema.** Migrations run before traffic moves, so a rollback
> leaves the database migrated forward. That is fine for additive migrations — a new table, a new
> nullable column — and **not** fine for a dropped or renamed column, where the older code will query
> something that no longer exists. If a release contains a destructive migration, take a dump first
> (§7) and restore it instead of rolling back.

Day-to-day:

```bash
# Logs (LOG_STACK=stderr, so application logs land here)
docker compose -p challenges-production -f deploy/compose.app.yml logs -f app

# Status
docker compose -p challenges-production -f deploy/compose.app.yml ps

# Artisan on the running stack
docker compose -p challenges-production -f deploy/compose.app.yml exec app php artisan about
```

## 6. Registering the webhooks

**Required after the first deploy of each environment** — until this runs, the bot receives nothing.
Do it once per environment, and again whenever `APP_URL` or a webhook secret changes.

The full walkthrough, including the silent-failure warning, is in
[setup-cpanel.md §7](setup-cpanel.md#7-registering-the-webhooks). Here it runs inside the container:

```bash
cd /opt/challenges
C="docker compose -p challenges-production -f deploy/compose.app.yml"

# Telegram — keeps both secrets in step, and registers the command menu
$C exec app php artisan telegram:set-webhook
$C exec app php artisan telegram:webhook-info
```

Bale has no artisan command — the webhook lifecycle is Telegram-only, so register it once via tinker:

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

The URL must be HTTPS on port 443 (or 88); the random path secret is Bale's only authenticity
mechanism.

If `telegram:webhook-info` reports a URL you do not recognise, a second deployment has claimed the
same bot. That is the staging/production bot collision warned about in §2.

## 7. Backups

The `mysql-data` volume is the only irreplaceable thing in the stack — the images are rebuildable and
the env files are short. Proof media in `storage-app` matters too if participants upload photos.

```bash
cd /opt/challenges
C="docker compose -p challenges-production -f deploy/compose.app.yml"

# Database
$C exec -T mysql mysqldump -u root -p"$DB_ROOT_PASSWORD" --single-transaction challenges \
    | gzip > "challenges-$(date +%F).sql.gz"

# Proof media
docker run --rm -v challenges-production_storage-app:/data -v "$PWD:/backup" \
    busybox tar czf /backup/storage-$(date +%F).tar.gz -C /data .
```

Restore:

```bash
gunzip < challenges-2026-01-15.sql.gz | $C exec -T mysql mysql -u root -p"$DB_ROOT_PASSWORD" challenges
```

Take a dump **before** any release containing a destructive migration (§5). Copy backups off the VM —
a backup that only exists on the machine it protects is not a backup.

## 8. Developing on the VM

For a VM used as a dev server rather than a deployment target. This runs Laravel Sail from the
published image, so it works on a machine with no PHP.

The chicken-and-egg problem: `vendor/bin/sail` is itself installed by Composer, so on a Docker-only
machine it does not exist until Composer has run — and Composer only runs inside the container that
`sail` would have started. `deploy/dev.sh` is the way in.

```bash
git clone https://github.com/mahadikhah/challanges.git ~/challenges
cd ~/challenges
deploy/dev.sh setup     # pulls the image, installs deps, generates a key, migrates, builds assets
```

Then:

```bash
deploy/dev.sh up                # start
deploy/dev.sh artisan migrate   # any artisan command
deploy/dev.sh composer require … 
deploy/dev.sh npm run dev       # Vite dev server
deploy/dev.sh test              # the full quality gate
deploy/dev.sh shell             # interactive shell
deploy/dev.sh down
```

Once `setup` has run, `vendor/bin/sail` exists and works. Prefer `dev.sh` anyway: the root
`compose.yaml` *builds* its PHP image from `vendor/laravel/sail/runtimes/8.5`, while `dev.sh` uses
the image CI already built.

This dev stack binds `APP_PORT` (default 80) directly on the host, so **do not run it on the same VM
as the production edge** — they will collide on port 80.

## 9. Where things differ from the VPS guide

If you already know [setup-vps.md](setup-vps.md), these are the deltas:

| VPS | Here |
|---|---|
| `supervisorctl` for queue workers | `queue` service, `--scale queue=N` via `QUEUE_WORKERS` |
| crontab line for the scheduler | `scheduler` service running `schedule:work` |
| certbot + renewal cron | Caddy, automatic |
| `git pull && composer install && npm run build` on the host | Prebuilt image from GHCR, built in CI |
| `storage/logs/laravel.log` | `docker compose logs` (`LOG_STACK=stderr`) |
| PHP-FPM on a unix socket | php-fpm on port 9000 over the stack-private network |

Unchanged: MySQL 8.4, the `database` queue/cache/session drivers (no Redis on either), the two-worker
ceiling, and the webhook registration flow.
