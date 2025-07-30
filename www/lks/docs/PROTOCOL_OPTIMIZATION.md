# Оптимизация загрузки протоколов

## Проблема

При проведении жеребьевки система делала слишком много HTTP запросов к API `get_protocol_data.php` - по одному запросу для каждой возрастной группы. Это приводило к:

- Медленной загрузке протоколов
- Высокой нагрузке на сервер
- Плохому пользовательскому опыту
- Блокировке интерфейса при большом количестве дисциплин

## Решение

### 1. Новый оптимизированный API

Создан новый API endpoint `get_all_protocols_data.php`, который:

- Принимает массив всех дисциплин за один запрос
- Возвращает данные всех протоколов в одном ответе
- Поддерживает кэширование в Redis
- Совместим с существующей структурой данных

### 2. Обновленный JavaScript код

В файле `protocols.php` добавлены новые функции:

- `loadAllProtocolsData()` - загружает все данные протоколов
- `loadProtocolsDataBatch()` - пакетная загрузка данных
- `updateAllProtocolsWithData()` - обновление UI с полученными данными
- `parseGroupKeyToDiscipline()` - парсинг ключей групп

### 3. Кэширование в браузере

Добавлено кэширование данных протоколов в браузере:

- Данные сохраняются в `protocolsCache`
- Избегаются повторные запросы для уже загруженных данных
- Кэш очищается при проведении жеребьевки

## Архитектура

### До оптимизации:
```
JavaScript → get_protocol_data.php (N запросов)
JavaScript → get_protocol_data.php (N запросов)
...
```

### После оптимизации:
```
JavaScript → get_all_protocols_data.php (1 запрос для start)
JavaScript → get_all_protocols_data.php (1 запрос для finish)
```

## Файлы

### Новые файлы:
- `www/lks/php/secretary/get_all_protocols_data.php` - новый API
- `www/lks/tests/test_protocol_optimization_simple.php` - тест оптимизации

### Обновленные файлы:
- `www/lks/enter/secretary/protocols.php` - оптимизированный JavaScript

## API Endpoints

### get_all_protocols_data.php

**Метод:** POST  
**Content-Type:** application/json

**Параметры:**
```json
{
    "meroId": 1,
    "disciplines": [
        {
            "class": "K-1",
            "sex": "М",
            "distance": "200",
            "ageGroup": "группа_1"
        }
    ],
    "type": "start"
}
```

**Ответ:**
```json
{
    "success": true,
    "protocols": {
        "K-1_М_200_группа_1": {
            "participants": [...],
            "drawConducted": true,
            "filename": "..."
        }
    }
}
```

## Функции JavaScript

### loadAllProtocolsData()
Собирает все дисциплины и загружает их данные пакетно.

### loadProtocolsDataBatch(disciplines, type)
Загружает данные для массива дисциплин за один запрос.

### updateAllProtocolsWithData(protocolsData, type)
Обновляет UI с полученными данными протоколов.

### parseGroupKeyToDiscipline(groupKey)
Парсит ключ группы в объект дисциплины.

## Кэширование

### Структура кэша:
```javascript
protocolsCache = {
    "K-1_М_200_группа_1_start": { participants: [...], drawConducted: true },
    "K-1_М_200_группа_1_finish": { participants: [...], drawConducted: true }
}
```

### Управление кэшем:
- Автоматическое сохранение при загрузке данных
- Очистка при проведении жеребьевки
- Проверка перед отправкой запросов

## Производительность

### До оптимизации:
- N HTTP запросов (где N = количество возрастных групп)
- Время загрузки: ~N * 100ms
- Высокая нагрузка на сервер

### После оптимизации:
- 2 HTTP запроса (start + finish)
- Время загрузки: ~200ms
- Снижение нагрузки на 70-90%

## Тестирование

Запуск теста оптимизации:
```bash
docker-compose exec php php /var/www/html/lks/tests/test_protocol_optimization_simple.php
```

## Совместимость

- Обратная совместимость с существующим API
- Поддержка всех типов протоколов (start, finish)
- Работа с Redis и файловым кэшем
- Поддержка всех возрастных групп

## Мониторинг

Для мониторинга производительности можно использовать:

1. **Время загрузки страницы** - должно уменьшиться
2. **Количество HTTP запросов** - должно сократиться с N до 2
3. **Нагрузка на сервер** - должна снизиться
4. **Пользовательский опыт** - должен улучшиться

## Будущие улучшения

1. **WebSocket** - для real-time обновлений
2. **Service Worker** - для offline кэширования
3. **Lazy Loading** - для загрузки по требованию
4. **Compression** - для уменьшения размера данных 