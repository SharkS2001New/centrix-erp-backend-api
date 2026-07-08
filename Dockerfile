# Laravel API — Apache + PHP 8.4 (matches composer.lock / Symfony 8.1)
FROM php:8.4-apache

RUN apt-get update && apt-get install -y \
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
    g++ \
    && rm -rf /var/lib/apt/lists/*

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
# Image build is production-only — PHPUnit/tests never run here (local dev + CI only).
ENV APP_ENV=production
ENV APP_DEBUG=false

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY . /var/www/html
WORKDIR /var/www/html
RUN git config --global --add safe.directory /var/www/html || true
# --no-dev: excludes phpunit and tests/ (see .dockerignore). No test scripts in composer.json.
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && test ! -e vendor/bin/phpunit \
    && php -m | grep -qi redis \
    && composer show predis/predis >/dev/null

COPY opcache.ini /usr/local/etc/php/conf.d/opcache.ini
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
