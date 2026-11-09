# Laravel API — Apache + PHP 8.4 (matches composer.lock / Symfony 8.1)
# Multi-stage: builder installs composer deps with build toolchain; runtime stays slim.
# Bookworm: Debian Trixie removed libc-client (required to build PHP IMAP).

# ─────────────────────────────────────────────────────────────────────────────
# Stage 1: build PHP extensions + composer vendor tree
# ─────────────────────────────────────────────────────────────────────────────
FROM php:8.4-apache-bookworm AS builder

RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        zip \
        unzip \
        curl \
        libzip-dev \
        libicu-dev \
        libbz2-dev \
        libpng-dev \
        libjpeg-dev \
        libreadline-dev \
        libfreetype6-dev \
        libc-client2007e-dev \
        libkrb5-dev \
        g++ \
    && update-ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# System CA bundle for PHP curl/openssl (avoids Composer curl error 60 on github dists).
RUN printf '%s\n' \
        'curl.cainfo=/etc/ssl/certs/ca-certificates.crt' \
        'openssl.cafile=/etc/ssl/certs/ca-certificates.crt' \
        > /usr/local/etc/php/conf.d/ssl-cafile.ini \
    && ln -sf /etc/ssl/certs/ca-certificates.crt /etc/ssl/cert.pem

# IMAP moved to PECL in PHP 8.4 (no docker-php-ext-install imap anymore).
RUN docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/ \
    && docker-php-ext-install -j"$(nproc)" \
        bz2 \
        intl \
        bcmath \
        opcache \
        calendar \
        pdo_mysql \
        gd \
        zip \
        pcntl \
    && yes '' | pecl install imap \
    && docker-php-ext-enable imap \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

ENV SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt \
    CURL_CA_BUNDLE=/etc/ssl/certs/ca-certificates.crt \
    COMPOSER_CAFILE=/etc/ssl/certs/ca-certificates.crt \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_INTERACTION=1

# Copy composer manifests first for better layer caching.
COPY composer.json composer.lock ./

ARG GITHUB_TOKEN=
# Install deps without running scripts (full source isn't copied yet).
RUN composer config --no-plugins use-github-api false \
    && if [ -n "$GITHUB_TOKEN" ]; then composer config --no-plugins github-oauth.github.com "$GITHUB_TOKEN"; fi \
    && composer install \
        --no-dev \
        --prefer-dist \
        --no-scripts \
        --no-autoloader \
        --no-progress \
    && composer config --no-plugins --unset github-oauth.github.com || true \
    && rm -f auth.json

# Now copy the rest of the app and regenerate the optimized autoloader.
COPY . .
RUN git config --global --add safe.directory /var/www/html || true \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && test ! -e vendor/bin/phpunit \
    && composer show predis/predis >/dev/null \
    # Strip VCS / docs / tests from vendor to shrink the layer.
    && find vendor -type d \( -name .git -o -name tests -o -name test -o -name docs -o -name examples \) -prune -exec rm -rf {} + \
    && find vendor -type f \( -name '*.md' -o -name '*.markdown' -o -name 'CHANGELOG*' -o -name 'UPGRADE*' \) -delete

# ─────────────────────────────────────────────────────────────────────────────
# Stage 2: runtime — no compilers, no dev headers, only runtime .so deps.
# ─────────────────────────────────────────────────────────────────────────────
FROM php:8.4-apache-bookworm

# Runtime libs matching the extensions compiled in the builder stage.
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates \
        default-mysql-client \
        libzip4 \
        libicu72 \
        libbz2-1.0 \
        libpng16-16 \
        libjpeg62-turbo \
        libfreetype6 \
        libc-client2007e \
        libkrb5-3 \
    && update-ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# Point PHP curl/openssl at the system CA bundle.
RUN printf '%s\n' \
        'curl.cainfo=/etc/ssl/certs/ca-certificates.crt' \
        'openssl.cafile=/etc/ssl/certs/ca-certificates.crt' \
        > /usr/local/etc/php/conf.d/ssl-cafile.ini \
    && ln -sf /etc/ssl/certs/ca-certificates.crt /etc/ssl/cert.pem

# Copy the compiled extensions and their enablers from the builder.
COPY --from=builder /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=builder /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && a2enmod rewrite headers deflate \
    && printf '%s\n' \
        '<IfModule mod_deflate.c>' \
        '  AddOutputFilterByType DEFLATE application/json text/plain text/html text/xml text/css application/javascript application/x-javascript' \
        '</IfModule>' \
        > /etc/apache2/conf-available/deflate-json.conf \
    && a2enconf deflate-json \
    && sed -i 's/Listen 80/Listen 8001/' /etc/apache2/ports.conf \
    && sed -i 's/:80/:8001/' /etc/apache2/sites-available/*.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

# stderr + JSON so cluster log scrapers can ship Laravel logs to Loki/Grafana.
ENV LOG_CHANNEL=stderr
ENV LOG_LEVEL=info
ENV LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter
ENV REDIS_CLIENT=phpredis
ENV CACHE_STORE=redis
ENV SESSION_DRIVER=redis
# Image build is production-only — PHPUnit/tests never run here (local machine only).
ENV APP_ENV=production
ENV APP_DEBUG=false

WORKDIR /var/www/html

# Copy the app + vendor/ in one layer from the builder.
COPY --from=builder --chown=www-data:www-data /var/www/html /var/www/html

COPY opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker-bootstrap.sh /usr/local/bin/docker-bootstrap.sh
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-bootstrap.sh /usr/local/bin/docker-entrypoint.sh \
    && mkdir -p \
        resources/views \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/framework/testing \
        storage/logs \
        storage/app/public \
        storage/app/private/backups/database \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache resources \
    && chmod -R ug+rwx storage bootstrap/cache \
    # Sanity: required extensions must be loaded at runtime.
    && php -m | grep -qi redis \
    && php -m | grep -qi pcntl \
    && php -m | grep -qi imap

# Runtime: docker-entrypoint.sh runs docker-bootstrap.sh (migrate, permissions sync, storage:link) before Apache.

EXPOSE 8001

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
