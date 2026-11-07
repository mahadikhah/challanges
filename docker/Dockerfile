# syntax=docker/dockerfile:1
#
# Production image for the Challenges platform.
#
# Four stages, two published targets:
#
#   vendor  — PHP deps only. Cached on composer.json/lock alone, so a source-only
#             change does not re-resolve packages.
#   assets  — builds on `vendor` because it MUST have PHP: the Wayfinder Vite
#             plugin shells out to `php artisan wayfinder:generate --with-form`
#             during `npm run build` (formVariants: true in vite.config.ts).
#             A node-only stage cannot build this frontend.
#   app     — php-fpm runtime. Target for the app/scheduler/queue containers.
#   web     — nginx serving public/ and proxying PHP to the `app` service.
#
# `vendor/`, `node_modules/` and `resources/js/{actions,routes,wayfinder}` are all
# gitignored, so every one of them is produced here rather than copied from the repo.
#
# PHP 8.5 matches the Sail dev runtime (compose.yaml) so dev, CI and prod agree.

ARG PHP_VERSION=8.5
ARG NODE_MAJOR=22


# ---------------------------------------------------------------------------
# Stage: vendor — composer dependencies
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-cli-bookworm AS vendor

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath gd intl zip exif pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dependency layer first: cached until composer.json/lock actually change.
# --no-scripts because artisan is not present yet (package:discover would fail).
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
        --no-autoloader

# Now the source, then finish what --no-scripts deferred.
COPY . .

# .dockerignore excludes storage/framework/{cache,sessions,views} so a
# developer's compiled views and sessions never ship — but that leaves the
# *directories* missing, and Blade's compiler validates its cache path the
# moment it is constructed. Any artisan command that touches a view then dies
# with "Please provide a valid cache path". That is not hypothetical: the
# `assets` stage below runs `wayfinder:generate` through the Vite plugin, and
# the report handler masks the real error behind a settings-table query.
# Created here rather than in `assets` so every stage FROM vendor inherits it.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && php artisan package:discover --ansi


# ---------------------------------------------------------------------------
# Stage: assets — frontend build (needs PHP for Wayfinder, hence FROM vendor)
# ---------------------------------------------------------------------------
FROM vendor AS assets

ARG NODE_MAJOR

# Vite inlines VITE_* variables into the bundle at build time, so this is the
# only place the value can be set — putting it in a runtime env file would have
# no effect. resources/js reads it via import.meta.env.
ARG VITE_APP_NAME=Challenges
ENV VITE_APP_NAME=${VITE_APP_NAME}

RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
    && curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# npm layer cached on the lockfile alone.
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Builds public/build, the font manifest, and (via the Wayfinder plugin)
# resources/js/{actions,routes,wayfinder}.
RUN npm run build


# ---------------------------------------------------------------------------
# Stage: app — php-fpm runtime (app, scheduler and queue all run this)
# ---------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-bookworm AS app

RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip4 libicu72 libpng16-16 libjpeg62-turbo libfreetype6 \
    && rm -rf /var/lib/apt/lists/*

# Extensions are compiled in `vendor`; copy the built .so files and their
# ini switches rather than paying for a second build toolchain here.
COPY --from=vendor /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=vendor /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

# opcache is statically compiled into the PHP 8.5 base images and enabled by
# default, so `docker-php-ext-enable opcache` would exit non-zero ("already
# compiled into PHP") and fail the build. docker/php.ini does the tuning.
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html

# Application code + composer deps, minus anything .dockerignore excluded.
COPY --from=vendor --chown=www-data:www-data /app ./
# Built frontend assets and the generated Wayfinder TS helpers.
COPY --from=assets --chown=www-data:www-data /app/public/build ./public/build
COPY --from=assets --chown=www-data:www-data /app/resources/js ./resources/js

COPY --chown=www-data:www-data docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/framework/{cache/data,sessions,views} storage/logs storage/app/public bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000
ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]


# ---------------------------------------------------------------------------
# Stage: web — nginx front door
# ---------------------------------------------------------------------------
FROM nginx:1.27-bookworm AS web

# curl is not in the nginx image and is not decoration here: it is the only
# HTTP client in the stack. The compose healthcheck uses it, and deploy.sh
# runs its post-deploy probe through `exec web curl` because the target VM has
# nothing installed but Docker.
RUN apt-get update && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

# Only the public document root is needed; PHP execution goes to the app service.
COPY --from=assets /app/public /var/www/html/public
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf

EXPOSE 80
