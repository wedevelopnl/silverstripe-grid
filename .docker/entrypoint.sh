#!/bin/sh
set -e

composer install --no-interaction

# FrankenPHP ships a default phpinfo() index.php. Replace it with the proper
# SilverStripe bootstrap after composer install makes the recipe available.
cp -f vendor/silverstripe/recipe-core/public/index.php /app/public/index.php

# Ensure all vendor package resources are exposed. composer install skips the
# vendor-expose step when the named Docker volume already has packages from a
# previous run (nothing new to install → no post-install event fires).
composer vendor-expose

# vendor-plugin uses realpath() to resolve library paths, which follows the
# symlink from vendor/wedevelopnl/silverstripe-grid → /module.
# Because /module is outside /app, getRelativePath() produces a broken path
# and the exposed resources are never created. Create them manually.
_res=/app/public/_resources/vendor/wedevelopnl/silverstripe-grid
mkdir -p "$_res/client"
[ -d /module/client/dist ] && ln -sfn /module/client/dist "$_res/client/dist"
[ -d /module/client/images ] && ln -sfn /module/client/images "$_res/client/images"
[ -d /module/client/lang ] && ln -sfn /module/client/lang "$_res/client/lang"
[ -d /module/lang ] && ln -sfn /module/lang "$_res/lang"

# Point the testbed's front-end stylesheet at the CSS framework matching the
# active grid adapter, so the rendered grid's emitted classes have matching CSS.
# Page.ss links the stable /css/grid-framework.css name; we symlink it per boot
# from $SS_GRID_ADAPTER. Bundled CSS exists for the three shipped presets; any
# other value (a custom FQCN) gets an empty file rather than a 404.
_css=/app/public/css
case "$(printf '%s' "${SS_GRID_ADAPTER:-}" | tr '[:upper:]' '[:lower:]')" in
    bootstrap) ln -sfn bootstrap.min.css "$_css/grid-framework.css" ;;
    tailwind)  ln -sfn tailwind.min.css  "$_css/grid-framework.css" ;;
    bulma)     ln -sfn bulma.min.css     "$_css/grid-framework.css" ;;
    *)         : > "$_css/grid-framework.css" ;;
esac

vendor/bin/sake dev/build flush=1

touch /tmp/.app-ready

exec frankenphp run --config /etc/caddy/Caddyfile
