# صورة التطوير لخادم Laravel (ADR-02): PHP 8.4 مع امتدادات MySQL و Redis والصور والترجمة
FROM php:8.4-cli-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev libpng-dev \
      libjpeg62-turbo-dev libfreetype6-dev libonig-dev default-mysql-client \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip gd pcntl exif opcache \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-dwahi.ini

WORKDIR /var/www/backend
