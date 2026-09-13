#!/usr/bin/env bash
#
# Creates the `testing` database alongside the development one, so
# `artisan test` has somewhere to run.
#
# A committed copy of vendor/laravel/sail/database/mysql/create-testing-database.sh
# (MIT, Laravel Sail). It exists here because deploy/compose.dev.yml must work on
# a fresh clone, where vendor/ has not been installed yet — that is the whole
# point of the dev-on-VM flow. Keep it in step if Sail's version changes.

mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS testing;
EOSQL

if [ -n "$MYSQL_USER" ]; then
mysql --user=root --password="$MYSQL_ROOT_PASSWORD" <<-EOSQL
    GRANT ALL PRIVILEGES ON \`testing%\`.* TO '$MYSQL_USER'@'%';
EOSQL
fi
