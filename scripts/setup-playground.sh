#!/usr/bin/env bash
#
# A local Statamic 6 site with this addon installed, for clicking through the
# Control Panel screens. Built at ./playground (gitignored, never committed).
#
#   * fresh statamic/statamic, Laravel pinned to ^12.40, SQLite
#   * this repo as a Composer path repository (src/ edits are live)
#   * LeadHub and Email Templates next to it, so the contact card and the
#     template picker have something to show
#   * CP assets built and published, a German super-user
#   * seed data from scripts/playground-seed.php: two mailboxes, threads
#     fetched through the in-memory IMAP fake the tests use, no real server
#
# Afterwards:
#
#   cd playground && php8.4 artisan serve --port=8231
#   open http://127.0.0.1:8231/cp  (login printed at the end)
#
# Re-running is safe: the scaffold is skipped when playground/ exists, assets
# and seed data are rebuilt every run. --fresh wipes it first.
#
# Private siblings need a token: COMPOSER_AUTH is built from `gh auth token`
# when it is not set already.

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ADDON_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
PLAYGROUND_DIR="$ADDON_DIR/playground"

LARAVEL_CONSTRAINT="${LARAVEL_CONSTRAINT:-^12.40}"
CP_EMAIL="${CP_EMAIL:-admin@example.com}"
CP_PASSWORD="${CP_PASSWORD:-password}"
PHP_BIN="${PHP_BIN:-php8.4}"
COMPOSER="${COMPOSER_BIN:-$(command -v composer)}"

composer() { "$PHP_BIN" "$COMPOSER" "$@"; }

if [ -z "${COMPOSER_AUTH:-}" ] && command -v gh >/dev/null; then
    COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$(gh auth token)\"}}"
    export COMPOSER_AUTH
fi

FRESH=0
[ "${1:-}" = "--fresh" ] && FRESH=1

step() { echo; echo "== $*"; }

if [ "$FRESH" = "1" ] && [ -d "$PLAYGROUND_DIR" ]; then
    rm -rf "$PLAYGROUND_DIR"
fi

if [ ! -d "$PLAYGROUND_DIR" ]; then
    step "Creating the Statamic site"
    composer create-project --prefer-dist --no-interaction --no-scripts statamic/statamic "$PLAYGROUND_DIR" 2>&1 | tail -3
    cd "$PLAYGROUND_DIR"

    [ -f .env ] || cp .env.example .env
    "$PHP_BIN" -r '
    $p=".env"; $e=file_get_contents($p);
    $lines=array_filter(preg_split("/\r?\n/",$e),fn($l)=>!preg_match("/^(DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD|APP_LOCALE|APP_URL)=/",$l));
    $lines[]="DB_CONNECTION=sqlite";
    $lines[]="DB_DATABASE=".__DIR__."/database/database.sqlite";
    $lines[]="APP_LOCALE=de";
    file_put_contents($p,implode("\n",$lines)."\n");'
    mkdir -p database && touch database/database.sqlite

    step "Wiring the addon as a path repository"
    composer config repositories.inbox path "$ADDON_DIR" --no-interaction
    composer config minimum-stability dev --no-interaction
    composer config prefer-stable true --no-interaction
    composer config --no-interaction allow-plugins.pixelfear/composer-dist-plugin true
    LARAVEL_CONSTRAINT="$LARAVEL_CONSTRAINT" "$PHP_BIN" -r '$f="composer.json";$j=json_decode(file_get_contents($f),true);$j["require"]["laravel/framework"]=getenv("LARAVEL_CONSTRAINT");file_put_contents($f,json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");'
    composer require "goldnead/statamic-inbox:@dev" goldnead/statamic-leadhub goldnead/statamic-email-templates -W --no-interaction --prefer-dist 2>&1 | tail -4

    "$PHP_BIN" artisan key:generate --force --no-interaction >/dev/null
fi

cd "$PLAYGROUND_DIR"

step "Migrating"
"$PHP_BIN" artisan migrate --force --no-interaction 2>&1 | tail -2

step "Publishing the Control Panel assets"
if [ ! -f "$ADDON_DIR/dist/build/manifest.json" ]; then
    ( cd "$ADDON_DIR" && npm install --no-audit --no-fund && npm run build )
fi
"$PHP_BIN" artisan vendor:publish --tag=inbox --force --no-interaction 2>&1 | tail -1

step "Seeding"
CP_EMAIL="$CP_EMAIL" CP_PASSWORD="$CP_PASSWORD" "$PHP_BIN" "$ADDON_DIR/scripts/playground-seed.php" "$PLAYGROUND_DIR"

echo
echo "Ready. Start it with:"
echo "    cd playground && $PHP_BIN artisan serve --port=8231"
echo "Control Panel: http://127.0.0.1:8231/cp   $CP_EMAIL / $CP_PASSWORD"
