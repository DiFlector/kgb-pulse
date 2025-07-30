FROM php:8.2-fpm

# Устанавливаем системные зависимости
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    zip \
    unzip \
    cron \
    postgresql-client \
    libpq-dev \
    libcurl4-openssl-dev \
    ca-certificates \
    && docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && docker-php-ext-install mbstring exif pcntl bcmath gd zip curl

# Устанавливаем Redis расширение
RUN pecl install redis && docker-php-ext-enable redis

# Очищаем кэш apt
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Устанавливаем Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Создаем пользователя для приложения
RUN groupadd -g 1000 www
RUN useradd -u 1000 -ms /bin/bash -g www www

# Копируем конфигурацию PHP
COPY php.ini /usr/local/etc/php/conf.d/custom.ini

# Устанавливаем рабочую директорию
WORKDIR /var/www/html

# Создаем директории для логов и кэша
RUN mkdir -p /var/log/php \
    && chown -R www-data:www-data /var/log/php \
    && chmod -R 755 /var/log/php

# Создаем директории для сайтов
RUN mkdir -p /var/www/html/lks /var/www/html/parking \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Копируем composer.json для установки зависимостей
COPY composer.json /var/www/html/composer.json

# Устанавливаем зависимости как root
USER root
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Устанавливаем правильные права доступа
RUN chown -R www-data:www-data /var/www/html

# Переключаемся на пользователя www-data
USER www-data

# Открываем порт 9000
EXPOSE 9000

# Запускаем PHP-FPM
CMD ["php-fpm"] 