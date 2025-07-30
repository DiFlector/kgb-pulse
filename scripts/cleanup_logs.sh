#!/bin/bash

# Скрипт очистки логов KGB-Pulse
# Запускается через cron еженедельно в воскресенье в 04:00

# Устанавливаем переменные
LOGS_DIR="/srv/pulse/logs"
RETENTION_DAYS=30

# Функция логирования
log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a /srv/pulse/logs/cleanup.log
}

log_message "Начало очистки логов"

# Проверяем существование директории логов
if [ ! -d "$LOGS_DIR" ]; then
    log_message "Директория логов не найдена: $LOGS_DIR"
    exit 1
fi

# Очищаем старые логи (старше 30 дней)
find "$LOGS_DIR" -name "*.log" -type f -mtime +$RETENTION_DAYS -delete
log_message "Удалены логи старше $RETENTION_DAYS дней"

# Очищаем временные файлы
find "$LOGS_DIR" -name "*.tmp" -type f -mtime +7 -delete
log_message "Удалены временные файлы старше 7 дней"

# Очищаем файлы автоматических скриптов
find "$LOGS_DIR" -name "auto_*.log" -type f -mtime +7 -delete
log_message "Удалены логи автоматических скриптов старше 7 дней"

# Очищаем файлы бэкапов логов
find "$LOGS_DIR" -name "*.log.*" -type f -mtime +14 -delete
log_message "Удалены архивы логов старше 14 дней"

# Проверяем свободное место
DISK_USAGE=$(df -h "$LOGS_DIR" | tail -1 | awk '{print $5}' | sed 's/%//')
log_message "Использование диска в директории логов: ${DISK_USAGE}%"

# Если использование диска больше 80%, удаляем больше старых файлов
if [ "$DISK_USAGE" -gt 80 ]; then
    log_message "ВНИМАНИЕ: Высокое использование диска, удаляем больше старых файлов"
    find "$LOGS_DIR" -name "*.log" -type f -mtime +7 -delete
    log_message "Удалены логи старше 7 дней из-за нехватки места"
fi

log_message "Очистка логов завершена успешно" 