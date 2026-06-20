# Laravel API — Apache + PHP (matches CI PHP 8.3)
FROM php:8.3-apache

RUN apt-get update && apt-get install -y \
    git \
    zip \
    curl \
    sudo \
    unzip \
    libicu-dev \
    libbz2-dev \
    libpng-dev \
    libjpeg-dev \
    libreadline-dev \
    libfreetype6-dev \
    g++ \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype=/usr/include/ --with-jpeg=/usr/include/
RUN docker-php-ext-install -j$(nproc) \
    bz2 \
    intl \
    iconv \
    bcmath \
    opcache \
    calendar \
    pdo_mysql \
    gd \
    zip

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
RUN a2enmod rewrite headers

RUN sed -i 's/Listen 80/Listen 8001/' /etc/apache2/ports.conf
RUN sed -i 's/:80/:8001/' /etc/apache2/sites-available/*.conf
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

ENV LOG_CHANNEL=stderr
ENV APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=

VOLUME /var/www/html

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY . /var/www/html
WORKDIR /var/www/html
RUN git config --global --add safe.directory /var/www/html || true
RUN composer install --no-dev --optimize-autoloader --no-interaction

RUN php artisan cache:clear || true
RUN php artisan view:clear && php artisan view:cache || true
RUN php artisan route:clear || true

COPY opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

RUN chown -R www-data:www-data storage bootstrap/cache && chmod -R 775 storage bootstrap/cache

EXPOSE 8001

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
