FROM php:8.3-fpm-alpine

ARG UID=1000
ARG GID=1000

# Użytkownik dopasowany do hosta (z fallbackiem gdy GID/UID zajęte)
RUN (addgroup -g ${GID} app 2>/dev/null || addgroup app) \
 && (adduser -D -G app -u ${UID} app 2>/dev/null || adduser -D -G app app) \
 && chown -R app:app /var/www

# System deps
RUN apk add --no-cache \
    bash git unzip icu-dev libzip-dev oniguruma-dev libpng-dev \
    libpq-dev postgresql-client autoconf make g++ \
    && pecl install xdebug || true

# PHP extensions
RUN docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) intl pdo pdo_pgsql opcache zip

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Konfiguracja PHP-FPM & INI
COPY php.ini /usr/local/etc/php/conf.d/app.ini
COPY www.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html
USER app

CMD ["php-fpm", "-F"]
