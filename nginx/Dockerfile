FROM nginx:alpine

# Устанавливаем необходимые пакеты
RUN apk add --no-cache openssl

# Создаем директории
RUN mkdir -p /etc/nginx/ssl \
    && mkdir -p /var/www/html/lks \
    && mkdir -p /var/www/html/parking

# Копируем конфигурационные файлы
COPY nginx.conf /etc/nginx/nginx.conf
COPY conf.d/default.conf /etc/nginx/conf.d/default.conf

# Копируем SSL сертификаты
COPY ssl/fullchain.pem /etc/nginx/ssl/fullchain.pem
COPY ssl/privkey.pem /etc/nginx/ssl/privkey.pem

# Устанавливаем правильные права для SSL сертификатов
RUN chmod 644 /etc/nginx/ssl/fullchain.pem \
    && chmod 600 /etc/nginx/ssl/privkey.pem

# Экспортируем порты
EXPOSE 80 443 8080 