FROM alpine:latest

# Устанавливаем необходимые пакеты
RUN apk add --no-cache \
    php82 \
    php82-cli \
    php82-pdo \
    php82-pdo_pgsql \
    php82-pgsql \
    php82-curl \
    php82-json \
    busybox \
    postgresql-client

# Создаем символическую ссылку для php
RUN ln -sf /usr/bin/php82 /usr/bin/php

# Создаем директории и настраиваем права
RUN mkdir -p /var/log/cron && \
    mkdir -p /scripts && \
    mkdir -p /etc/cron.d

# Копируем crontab файл
COPY crontab /etc/crontabs/root
RUN chmod 0644 /etc/crontabs/root

# Создаем скрипт запуска
RUN echo '#!/bin/sh' > /start.sh && \
    echo 'touch /var/log/cron/cron.log' >> /start.sh && \
    echo 'chmod 0644 /var/log/cron/cron.log' >> /start.sh && \
    echo 'crond -f -L /var/log/cron/cron.log' >> /start.sh && \
    chmod +x /start.sh

# Устанавливаем рабочую директорию
WORKDIR /scripts

# Запускаем crond через скрипт
CMD ["/start.sh"] 