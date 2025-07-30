#!/bin/bash

# Скрипт резервного копирования базы данных KGB-Pulse
# Запускается через cron ежедневно в 02:00

# Устанавливаем переменные
BACKUP_DIR="/srv/pulse/backups"
DATE=$(date +%Y%m%d_%H%M%S)
DB_NAME="pulse_rowing_db"
DB_USER="pulse_user"
DB_HOST="postgres"
BACKUP_FILE="${BACKUP_DIR}/backup_${DB_NAME}_${DATE}.sql"

# Создаем директорию для бэкапов если её нет
mkdir -p "$BACKUP_DIR"

# Функция логирования
log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a /srv/pulse/logs/backup.log
}

log_message "Начало резервного копирования базы данных"

# Проверяем доступность базы данных
if ! docker exec pulse-postgres-1 pg_isready -U "$DB_USER" -d "$DB_NAME" > /dev/null 2>&1; then
    log_message "ОШИБКА: База данных недоступна"
    exit 1
fi

# Создаем резервную копию
if docker exec pulse-postgres-1 pg_dump -U "$DB_USER" -h "$DB_HOST" -d "$DB_NAME" --no-password > "$BACKUP_FILE"; then
    log_message "Резервная копия создана: $BACKUP_FILE"
    
    # Сжимаем файл
    gzip "$BACKUP_FILE"
    log_message "Файл сжат: ${BACKUP_FILE}.gz"
    
    # Удаляем старые бэкапы (оставляем последние 7 дней)
    find "$BACKUP_DIR" -name "backup_*.sql.gz" -mtime +7 -delete
    log_message "Удалены старые резервные копии (старше 7 дней)"
    
    # Проверяем размер файла
    FILE_SIZE=$(du -h "${BACKUP_FILE}.gz" | cut -f1)
    log_message "Размер резервной копии: $FILE_SIZE"
    
else
    log_message "ОШИБКА: Не удалось создать резервную копию"
    exit 1
fi

log_message "Резервное копирование завершено успешно" 