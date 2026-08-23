ARG PHP_VERSION=8.3
FROM dunglas/frankenphp:php${PHP_VERSION}

RUN install-php-extensions \
    intl \
    gd \
    mysqli \
    pdo_mysql \
    zip \
    bcmath \
    exif \
    pcov

RUN echo 'pcov.directory=/module/src' > /usr/local/etc/php/conf.d/99-pcov.ini

# Collecting coverage over the full suite exceeds PHP's 128M default under
# phpunit 12 / php-code-coverage 12 — it OOMs partway through, and only on
# some `executionOrder="random"` seeds, so it reads as a flaky test rather
# than a memory ceiling. This image is the test/dev harness only.
RUN echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/99-memory.ini

# Major-pinned: :latest would silently adopt a future Composer major release
# mid-build, and the binary it copies runs against this module's composer.json.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Composer defaults to 12 concurrent downloads. The boot install (entrypoint.sh)
# runs on shared-IP CI runners twelve times per pipeline; halving the concurrency
# trades a few seconds of boot for markedly fewer resets and 429s, which cost a
# whole job. NO_AUDIT drops one more round trip — this image is the throwaway
# test harness, and the host-side composer install in CI still audits.
ENV COMPOSER_MAX_PARALLEL_HTTP=6 \
    COMPOSER_NO_AUDIT=1

WORKDIR /app

ARG COMPOSER_FILE=app/composer.json
COPY ${COMPOSER_FILE} /app/composer.json

# SilverStripe requires Page and PageController classes in the project.
# These are generated here (not stored in the repo) to avoid duplicate
# class detection when the module is symlinked into vendor/.
# SilverStripe discovers modules by looking for _config/ or _config.php.
# Without this, /app/src/ is not scanned and Page/PageController are invisible
# to the class manifest (which breaks temp database creation in tests).
RUN mkdir -p /app/src /app/_config \
    && printf '<?php\n\nuse SilverStripe\\CMS\\Model\\SiteTree;\n\nclass Page extends SiteTree\n{\n}\n' > /app/src/Page.php \
    && printf '<?php\n\nuse SilverStripe\\CMS\\Controllers\\ContentController;\n\nclass PageController extends ContentController\n{\n}\n' > /app/src/PageController.php

# Harness-owned page types (MultiZonePage). Merges into the /app/src created
# above, so the generated Page/PageController stay in place.
COPY app/src/ /app/src/

COPY app/_config/ /app/_config/
COPY app/templates/ /app/templates/
COPY app/infection.json5 /app/infection.json5
COPY app/phpunit.xml.dist /app/phpunit.xml.dist
COPY app/phpstan.neon.dist /app/phpstan.neon.dist
COPY app/phpstan-php85.neon.dist /app/phpstan-php85.neon.dist
COPY app/rector.php /app/rector.php

COPY assets/css/ /app/public/css/

COPY Caddyfile /etc/caddy/Caddyfile
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
