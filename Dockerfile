# Laravel API — Apache + PHP 8.4 (matches composer.lock / Symfony 8.1)
# Bookworm: Debian Trixie removed libc-client (required to build PHP IMAP).
FROM php:8.4-apache-bookworm

RUN apt-get update && apt-get install -y \
    ca-certificates \
    git \
    zip \
    unzip \
    curl \
    libzip-dev \
    default-mysql-client \
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

# Point PHP curl/openssl at the system CA bundle (avoids Composer curl error 60
# when downloading GitHub dists such as aws/aws-crt-php).
RUN printf '%s\n' \
    'curl.cainfo=/etc/ssl/certs/ca-certificates.crt' \
    'openssl.cafile=/etc/ssl/certs/ca-certificates.crt' \
    > /usr/local/etc/php/conf.d/ssl-cafile.ini \
    && ln -sf /etc/ssl/certs/ca-certificates.crt /etc/ssl/cert.pem

# IMAP moved to PECL in PHP 8.4 (no longer docker-php-ext-install imap).
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
    && docker-php-ext-enable redis

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && a2enmod rewrite headers \
    && sed -i 's/Listen 80/Listen 8001/' /etc/apache2/ports.conf \
    && sed -i 's/:80/:8001/' /etc/apache2/sites-available/*.conf \
    && echo "ServerName localhost" >> /etc/apache2/apache2.conf

ENV LOG_CHANNEL=stderr
ENV REDIS_CLIENT=phpredis
ENV CACHE_STORE=redis
ENV SESSION_DRIVER=redis
# Image build is production-only — PHPUnit/tests never run here (local machine only).
ENV APP_ENV=production
ENV APP_DEBUG=false

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY . /var/www/html
WORKDIR /var/www/html
RUN git config --global --add safe.directory /var/www/html || true
# Prefer GitHub archive/codeload over api.github.com zipballs (fewer SSL/rate-limit failures).
ARG GITHUB_TOKEN=
ENV SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt \
    CURL_CA_BUNDLE=/etc/ssl/certs/ca-certificates.crt \
    COMPOSER_CAFILE=/etc/ssl/certs/ca-certificates.crt
# --no-dev: excludes phpunit and tests/ (see .dockerignore). No test scripts in composer.json.
RUN composer config --no-plugins --no-interaction use-github-api false \
    && if [ -n "$GITHUB_TOKEN" ]; then composer config --no-plugins --no-interaction github-oauth.github.com "$GITHUB_TOKEN"; fi \
    && composer install --no-dev --optimize-autoloader --no-interaction \
    && composer config --no-plugins --no-interaction --unset github-oauth.github.com || true \
    && rm -f auth.json \
    && test ! -e vendor/bin/phpunit \
    && php -m | grep -qi redis \
    && php -m | grep -qi pcntl \
    && composer show predis/predis >/dev/null

COPY opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker-bootstrap.sh /usr/local/bin/docker-bootstrap.sh
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-bootstrap.sh /usr/local/bin/docker-entrypoint.sh

RUN mkdir -p resources/views storage/framework/cache/data storage/framework/sessions storage/framework/views storage/framework/testing storage/logs storage/app/public storage/app/private/backups/database bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache resources \
    && chmod -R ug+rwx storage bootstrap/cache

# Runtime: docker-entrypoint.sh runs docker-bootstrap.sh (migrate, permissions sync, storage:link) before Apache.

EXPOSE 8001

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
