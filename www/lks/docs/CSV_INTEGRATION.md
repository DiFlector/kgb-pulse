# Интеграция CSV протоколов с интерфейсом

## Обзор

Система генерации CSV протоколов интегрирована с интерфейсом секретаря на странице `protocols.php`. При нажатии кнопки "Скачать стартовые" пользователь может выбрать формат скачивания, включая новый CSV формат.

## Как это работает

### 1. Кнопка "Скачать стартовые"

На странице `protocols.php` есть кнопка:
```html
<button type="button" class="btn btn-outline-success" id="download-start-protocols-btn">
    <i class="fas fa-download"></i> Скачать стартовые
</button>
```

### 2. JavaScript обработчик

В файле `protocols_new.js` кнопка привязана к функции `downloadAllProtocols('start')`:

```javascript
document.addEventListener('click', (e) => {
    if (e.target.closest('#download-start-protocols-btn')) {
        this.downloadAllProtocols('start');
    }
});
```

### 3. Модальное окно выбора формата

Функция `showDownloadFormatModal()` создает модальное окно с тремя опциями:
- **CSV** - новый формат на основе шаблонов
- **PDF** - существующий формат
- **Excel** - существующий формат

### 4. Обработка CSV формата

При выборе CSV формата вызывается функция `downloadAllCsvProtocols()`, которая:

1. Фильтрует протоколы по заполненности
2. Для каждого протокола вызывает `downloadSingleCsvProtocol()`
3. Скачивает каждый протокол отдельно через API `generate_csv_protocol.php`

## API Endpoints

### Скачивание CSV протокола
```
GET /lks/php/secretary/generate_csv_protocol.php
```

**Параметры:**
- `group_key` - ключ возрастной группы (например, "1_K-1_M_200_группа 1: 18-29")
- `mero_id` - ID мероприятия (champn)
- `protocol_type` - тип протокола ("start" или "finish")

**Пример:**
```
/lks/php/secretary/generate_csv_protocol.php?group_key=1_K-1_M_200_группа%201:%2018-29&mero_id=1&protocol_type=start
```

**Ответ:**
- HTTP 200: CSV файл с правильными заголовками
- HTTP 403: Доступ запрещен
- HTTP 404: Протокол не найден
- HTTP 500: Ошибка сервера

## Структура файлов

### JavaScript файлы
- `www/lks/js/secretary/protocols_new.js` - основной файл управления протоколами

### PHP файлы
- `www/lks/php/secretary/generate_csv_protocol.php` - API для скачивания CSV
- `www/lks/php/secretary/CsvProtocolGenerator.php` - класс генератора

### Шаблоны
- `www/lks/files/template/Start_solo.csv` - для одиночных дисциплин
- `www/lks/files/template/Start_group.csv` - для групповых дисциплин
- `www/lks/files/template/Start_dragons.csv` - для драконов

## Алгоритм работы

### 1. Пользователь нажимает "Скачать стартовые"

### 2. Система проверяет наличие протоколов
```javascript
const filteredProtocols = this.protocolsData.filter(protocol => {
    return protocol.ageGroups.some(ageGroup => {
        return ageGroup.participants && ageGroup.participants.length > 0;
    });
});
```

### 3. Показывается модальное окно выбора формата

### 4. При выборе CSV:
```javascript
async downloadAllCsvProtocols(protocolType) {
    for (const protocol of filteredProtocols) {
        for (const ageGroup of protocol.ageGroups) {
            if (ageGroup.participants && ageGroup.participants.length > 0) {
                await this.downloadSingleCsvProtocol(ageGroup.redisKey, protocolType);
            }
        }
    }
}
```

### 5. Каждый протокол скачивается отдельно
```javascript
async downloadSingleCsvProtocol(groupKey, protocolType) {
    const url = `/lks/php/secretary/generate_csv_protocol.php?group_key=${encodeURIComponent(groupKey)}&mero_id=${this.currentMeroId}&protocol_type=${protocolType}`;
    // ... скачивание файла
}
```

## Особенности реализации

### Множественное скачивание
- Каждый протокол скачивается как отдельный файл
- Имена файлов: `protocol_{groupKey}_{protocolType}.csv`
- Пользователь получает уведомление о количестве скачанных файлов

### Обработка ошибок
- Проверка авторизации пользователя
- Валидация параметров запроса
- Логирование ошибок в системный лог
- Пользовательские уведомления об ошибках

### Безопасность
- Проверка прав доступа (Secretary, SuperUser, Admin)
- Валидация входных данных
- Защита от SQL-инъекций через prepared statements

## Тестирование

### Запуск тестов
```bash
# Тест генератора CSV
php www/lks/tests/test_csv_protocol_generator.php

# Тест интеграции
php www/lks/tests/test_csv_integration.php
```

### Ручное тестирование
1. Войдите как секретарь
2. Выберите мероприятие
3. Перейдите на страницу протоколов
4. Нажмите "Скачать стартовые"
5. Выберите формат "CSV"
6. Проверьте скачанные файлы

## Отладка

### Логирование
Все ошибки логируются с префиксом:
```
=== ОШИБКА ГЕНЕРАЦИИ CSV ПРОТОКОЛА ===
```

### Консоль браузера
JavaScript ошибки выводятся в консоль браузера:
```javascript
console.log('Скачивание CSV протокола:', groupKey, protocolType);
```

### Проверка файлов
- Убедитесь, что JSON файлы протоколов существуют
- Проверьте наличие шаблонов CSV
- Убедитесь в правах доступа к файлам

## Известные проблемы

### 1. Блокировка браузера
При скачивании множества файлов браузер может заблокировать автоматическое скачивание. Решение: скачивать файлы по одному с задержкой.

### 2. Кодировка
CSV файлы содержат BOM для корректного отображения кириллицы в Excel.

### 3. Пустые протоколы
Протоколы без участников не скачиваются.

## Будущие улучшения

1. **Архив ZIP** - скачивание всех CSV в одном ZIP архиве
2. **Прогресс-бар** - отображение прогресса скачивания
3. **Предварительный просмотр** - показ содержимого перед скачиванием
4. **Настройки формата** - возможность настройки разделителей и кодировки 