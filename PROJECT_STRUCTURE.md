# Структура проекта KGB-Pulse

## Основные директории

```
/
├── data/                  # Данные и конфигурация
│   ├── db/               # SQL файлы инициализации
│   │   ├── 00_init_database.sql      # Создание базы
│   │   ├── 01_init.sql               # Начальные таблицы
│   │   ├── 02_seed_users.sql         # Пользователи по умолчанию
│   │   └── 15_password_reset_tables.sql # Сброс паролей
│   ├── redis/            # Данные Redis
│   ├── all_sportsmen.json # Данные спортсменов
│   ├── sportsmen_data.csv # CSV данные
│   └── sportsmen_data.json # JSON данные
├── images/               # Docker образы и конфигурации
│   ├── cron/            # Конфигурация Cron
│   │   ├── Dockerfile
│   │   └── crontab
│   └── php/             # Конфигурация PHP
│       ├── Dockerfile
│       ├── composer.json
│       └── php.ini
├── logs/                 # Логи контейнеров Docker
├── nginx/                # Конфигурация Nginx
│   ├── conf.d/          # Конфигурации сайтов
│   │   ├── default.conf
│   │   └── default.conf.bak
│   ├── ssl/             # SSL сертификаты
│   │   ├── generate_cert.sh
│   │   └── openssl.conf
│   ├── Dockerfile
│   └── nginx.conf
├── pgdata/              # Файлы базы данных PostgreSQL
├── scripts/             # Скрипты обслуживания
│   ├── backup/          # Скрипты резервного копирования
│   │   ├── backup_redis.sh
│   │   └── yearly/      # Ежегодные резервные копии
│   ├── auto_close_registration.php    # Автозакрытие регистрации
│   ├── auto_mark_no_show.php         # Автоотметка неявки
│   ├── backup_database.sh            # Резервное копирование БД
│   ├── cleanup_logs.sh               # Очистка логов
│   ├── cleanup_old_data.php          # Очистка старых данных
│   ├── monitor_system.php            # Мониторинг системы
│   ├── security_check.php            # Проверка безопасности
│   └── start_system.ps1              # Запуск системы (Windows)
├── vendor/              # Composer зависимости
├── composer.json        # Зависимости проекта
├── docker-compose.yaml  # Docker конфигурация
├── env.example         # Пример переменных окружения
├── PROJECT_STRUCTURE.md # Этот файл
├── README.txt          # Описание проекта
└── TZ.md               # Техническое задание
```

## Веб-приложение (www/lks/)

```
lks/
├── css/                 # Стили CSS
│   ├── style.css       # Основные стили
│   ├── style-clean.css # Очищенные стили
│   └── homepage-final.css # Стили главной страницы
├── enter/              # Авторизованная зона
│   ├── includes/       # Общие компоненты
│   │   ├── header.php  # Шапка сайта
│   │   └── footer.php  # Подвал сайта
│   ├── common/         # Общие страницы
│   │   └── profile.php # Профиль пользователя
│   ├── admin/          # Интерфейс администратора
│   │   ├── index.php   # Главная админа
│   │   ├── boats.php   # Управление лодками
│   │   ├── data.php    # Управление данными
│   │   ├── events.php  # Управление мероприятиями
│   │   ├── statistics.php # Статистика
│   │   └── users.php   # Управление пользователями
│   ├── organizer/      # Интерфейс организатора
│   │   ├── index.php   # Главная организатора
│   │   ├── calendar.php # Календарь
│   │   ├── create-event.php # Создание мероприятий
│   │   ├── edit-registration.php # Редактирование регистраций
│   │   ├── edit-team.php # Редактирование команд
│   │   ├── events.php  # Мероприятия
│   │   ├── queue.php   # Очередь регистраций
│   │   └── registrations.php # Список регистраций
│   ├── secretary/      # Интерфейс секретаря
│   │   ├── index.php   # Главная секретаря
│   │   ├── events.php  # Мероприятия
│   │   ├── main.php    # Основная страница
│   │   ├── protocols.php # Протоколы
│   │   └── results.php # Результаты
│   └── user/           # Интерфейс пользователя
│       ├── index.php   # Главная пользователя
│       ├── calendar.php # Календарь
│       └── statistics.php # Статистика
├── files/              # Загруженные файлы
│   ├── excel/         # Excel файлы
│   ├── pdf/           # PDF файлы
│   ├── polojenia/     # Положения мероприятий
│   ├── results/       # Результаты соревнований
│   ├── protocol/      # Протоколы соревнований
│   ├── sluzebnoe/     # Служебные файлы
│   ├── temp/          # Временные файлы
│   ├── json/          # JSON файлы
│   └── template/      # Шаблоны файлов
│       ├── Start_dragons.xlsx    # Шаблон стартовых протоколов драконов
│       ├── Finish_dragons.xlsx   # Шаблон финишных протоколов драконов
│       ├── Start_solo.xlsx       # Шаблон одиночных стартовых протоколов
│       ├── Finish_solo.xlsx      # Шаблон одиночных финишных протоколов
│       ├── Start_group.xlsx      # Шаблон групповых стартовых протоколов
│       ├── Finish_group.xlsx     # Шаблон групповых финишных протоколов
│       └── technical_results.xlsx # Шаблон технических результатов
├── fonts/             # Шрифты
├── html/              # Статические HTML страницы
│   ├── 401.html       # Неавторизован
│   ├── 403.html       # Доступ запрещен
│   ├── 404.html       # Страница не найдена
│   └── 50x.html       # Ошибка сервера
├── images/            # Изображения
│   └── logo_new.svg   # Логотип
├── includes/          # Подключаемые библиотеки
├── js/                # JavaScript файлы
│   ├── admin/         # JS администратора
│   │   └── admin.js
│   ├── libs/          # Библиотеки JavaScript
│   │   └── jquery/
│   │       └── jquery-3.7.1.min.js
│   ├── organizer/     # JS организатора
│   │   └── organizer.js
│   ├── secretary/     # JS секретаря
│   │   ├── protocols.js
│   │   ├── results.js
│   │   └── secretary.js
│   ├── user/          # JS пользователя
│   │   └── user.js
│   ├── main.js        # Основной JS
│   ├── registration.js # JS регистрации
│   ├── modal-fix.js   # Исправления модальных окон
│   └── sidebar-manager.js # Управление боковой панелью
├── json/              # JSON файлы
├── php/               # Backend PHP
│   ├── common/        # Общие классы
│   │   ├── Auth.php               # Авторизация
│   │   ├── EventRegistration.php  # Регистрация на мероприятия
│   │   ├── FileUploadHandler.php  # Обработка загрузки файлов
│   │   ├── RegistrationManager.php # Управление регистрациями
│   │   └── SessionManager.php     # Управление сессиями
│   ├── db/            # Работа с базой данных
│   │   └── Database.php # Класс для работы с БД
│   ├── admin/         # API администратора
│   ├── organizer/     # API организатора
│   │   ├── edit_registration.php  # Редактирование регистраций
│   │   ├── confirm_payment.php    # Подтверждение оплаты
│   │   └── get_queue.php          # Получение очереди
│   ├── secretary/     # API секретаря
│   ├── user/          # API пользователя
│   └── helpers.php    # Вспомогательные функции
├── tests/             # Тестовые файлы (очищены от устаревших)
│   ├── test_class_distance_structure.php # Тест структуры классов
│   └── fix_meros_oid_champn_alignment.php # Исправление выравнивания meros
├── auth.php           # Авторизация
├── index.php          # Главная страница
├── login.php          # Страница входа
├── register.php       # Страница регистрации
├── events.php         # Страница мероприятий
├── forgot-password.php # Восстановление пароля
└── reset-password.php # Сброс пароля
```

## База данных PostgreSQL

### Типы данных (ENUM)

1. **boats** - Типы лодок
   - 'D-10', 'K-1', 'K-2', 'K-4', 'C-1', 'C-2', 'C-4', 'HD-1', 'OD-1', 'OD-2', 'OC-1'

2. **rights** - Права доступа
   - 'Sportsman', 'Admin', 'Organizer', 'Secretary', 'SuperUser'

3. **statuses** - Статусы участников
   - 'В очереди', 'Зарегистрирован', 'Подтверждён', 'Ожидание команды', 'Дисквалифицирован', 'Неявка'

4. **sportzvanias** - Спортивные звания
   - 'ЗМС', 'МСМК', 'МССССР', 'МСР', 'МСсуч', 'КМС', '1вр', '2вр', '3вр', 'БР'

5. **merostat** - Статусы мероприятий
   - 'В ожидании', 'Регистрация', 'Регистрация закрыта', 'Перенесено', 'Результаты', 'Завершено'

6. **notification_type** - Типы уведомлений
   - 'registration', 'participation', 'status_change', 'system'

### Основные таблицы

1. **users** - Пользователи системы
   - `oid` (SERIAL, PK) - Внутренний ID, вешний ключ
   - `userid` (INTEGER, UNIQUE) - Номер спортсмена
   - `email` (TEXT, UNIQUE) - Email адрес
   - `password` (TEXT) - Пароль
   - `fio` (TEXT) - ФИО
   - `sex` (VARCHAR(1)) - Пол ('М'/'Ж')
   - `telephone` (TEXT, UNIQUE) - Телефон
   - `birthdata` (DATE) - Дата рождения
   - `country` (TEXT) - Страна
   - `city` (TEXT) - Город
   - `accessrights` (rights) - Права доступа (по умолчанию 'Sportsman')
   - `boats` (boats[]) - Массив типов лодок
   - `sportzvanie` (sportzvanias) - Спортивное звание (по умолчанию 'БР')

2. **meros** - Мероприятия
   - `oid` (SERIAL, PK) - Внутренний ID, внешний ключ
   - `champn` (INTEGER, UNIQUE) - Пользовательский номер мероприятия
   - `merodata` (TEXT) - Дата проведения
   - `meroname` (TEXT) - Название мероприятия
   - `class_distance` (JSONB) - Классы, дистанции, пол, возрастные группы (пример: {"K-1": {"sex": ["M", "W"], "dist": ["200, 500, 1000", "200, 500, 1000"], "age_group": ["группа 1: 27-49", "группа 1: 27-49"]}})
   - `defcost` (NUMERIC(10,2)) - Базовая стоимость
   - `filepolojenie` (TEXT) - Файл положения
   - `fileprotokol` (TEXT) - Файл протокола
   - `fileresults` (TEXT) - Файл результатов
   - `status` (merostat) - Статус мероприятия
   - `created_by` (INTEGER) - Создатель (ссылка на users.oid)

3. **listreg** - Регистрации на мероприятия
   - `oid` (SERIAL, PK) - Внутренний ID
   - `users_oid` (INTEGER) - Участник (ссылка на users.oid)
   - `meros_oid` (INTEGER) - Мероприятие (ссылка на meros.oid)
   - `teams_oid` (INTEGER) - Команда (ссылка на teams.oid)
   - `discipline` (JSONB) - Выбранные классы и дистанции участника (пример: {"K-1": {"sex": ["M"], "dist": ["200, 500, 1000"]}})
   - `oplata` (BOOLEAN) - Оплачено (по умолчанию FALSE)
   - `cost` (NUMERIC(10,2)) - Стоимость участия (по умолчанию 0)
   - `status` (statuses) - Статус регистрации
   - `role` (TEXT) - Роль в команде (по умолчанию 'member')

4. **teams** - Команды
   - `oid` (SERIAL, PK) - Внутренний ID
   - `teamid` (INTEGER, UNIQUE) - ID команды
   - `teamname` (TEXT) - Название команды
   - `team_name` (TEXT) - Дублирующее поле для совместимости
   - `teamcity` (TEXT) - Город команды
   - `persons_amount` (INTEGER) - Количество участников (по умолчанию 0)
   - `persons_all` (INTEGER) - Общее количество мест (по умолчанию 14)
   - `another_team` (INTEGER) - Связанная команда
   - `class` (TEXT) - Класс лодки для команды

5. **user_statistic** - Статистика пользователей
   - `oid` (SERIAL, PK) - Внутренний ID
   - `meroname` (TEXT) - Название мероприятия
   - `place` (TEXT) - Занятое место
   - `time` (TIME) - Время результата
   - `team` (TEXT) - Команда
   - `data` (DATE) - Дата соревнования
   - `race_type` (TEXT) - Тип гонки
   - `users_oid` (INTEGER) - Пользователь (ссылка на users.oid)

### Служебные таблицы

1. **notifications** - Уведомления
   - `oid` (SERIAL, PK) - Внутренний ID
   - `userid` (INTEGER) - Пользователь (ссылка на users.oid)
   - `type` (notification_type) - Тип уведомления
   - `title` (TEXT) - Заголовок уведомления
   - `message` (TEXT) - Текст сообщения
   - `is_read` (BOOLEAN) - Прочитано (по умолчанию FALSE)
   - `created_at` (TIMESTAMP) - Время создания
   - `email_sent` (BOOLEAN) - Email отправлен (по умолчанию FALSE)

2. **login_attempts** - Попытки входа
   - `oid` (SERIAL, PK) - Внутренний ID
   - `users_oid` (INTEGER) - Пользователь (ссылка на users.oid)
   - `ip` (VARCHAR(45)) - IP-адрес
   - `success` (BOOLEAN) - Успешный вход
   - `attempt_time` (TIMESTAMP) - Время попытки

3. **user_actions** - Действия пользователей
   - `oid` (SERIAL, PK) - Внутренний ID
   - `users_oid` (INTEGER) - Пользователь (ссылка на users.oid)
   - `action` (TEXT) - Описание действия
   - `ip_address` (VARCHAR(45)) - IP-адрес
   - `created_at` (TIMESTAMP) - Время действия

4. **system_events** - Системные события
   - `oid` (SERIAL, PK) - Внутренний ID
   - `event_type` (VARCHAR(50)) - Тип события
   - `description` (TEXT) - Описание события
   - `severity` (VARCHAR(20)) - Уровень важности
   - `created_at` (TIMESTAMP) - Время события

### Индексы для оптимизации

#### Основные таблицы
- `idx_users_email` - users(email)
- `idx_users_telephone` - users(telephone)
- `idx_users_accessrights` - users(accessrights)
- `idx_listreg_userid` - listreg(userid)
- `idx_listreg_champn` - listreg(champn)
- `idx_listreg_status` - listreg(status)
- `idx_meros_status` - meros(status)
- `idx_meros_created_by` - meros(created_by)
- `idx_user_statistic_userid` - user_statistic(userid)
- `idx_user_statistic_data` - user_statistic(data)

#### Служебные таблицы
- `idx_notifications_userid` - notifications(userid)
- `idx_notifications_is_read` - notifications(is_read)
- `idx_login_attempts_ip_time` - login_attempts(ip, attempt_time)
- `idx_login_attempts_userid` - login_attempts(userid)
- `idx_user_actions_userid` - user_actions(userid)
- `idx_user_actions_created_at` - user_actions(created_at)
- `idx_system_events_type_time` - system_events(event_type, created_at)
- `idx_system_events_severity` - system_events(severity)

## Redis

### Структура данных

1. **Сессии** - `session:*`
   - Данные авторизованных пользователей
   - TTL: 24 часа
   - Структура: JSON с данными пользователя

2. **Протоколы** - `protocol:*`
   - Стартовые протоколы соревнований
   - Промежуточные результаты
   - Финишные протоколы
   - TTL: настраивается по мероприятию

3. **Кэш** - `cache:*`
   - Кэширование запросов к БД
   - Статистические данные
   - TTL: 1 час

## Архитектура системы

### Роли пользователей

1. **Admin (userid: 1-50)** - Полный доступ к системе
2. **Organizer (userid: 51-150)** - Управление мероприятиями
3. **Secretary (userid: 151-250)** - Проведение соревнований
4. **Sportsman (userid: 1000+)** - Участие в соревнованиях

### Статусы участников

1. **"В очереди"** - Подана заявка, ожидает подтверждения
2. **"Подтверждён"** - Заявка подтверждена организатором
3. **"Зарегистрирован"** - Полная регистрация с оплатой
4. **"Ожидание команды"** - Ожидает формирования команды
5. **"Неявка"** - Не явился на соревнования
6. **"Дисквалифицирован"** - Дисквалифицирован судьями

### Логика обработки статусов

- Кнопка "Неявка" доступна для статусов: "В очереди", "Подтверждён", "Ожидание команды"
- Кнопка "Неявка" НЕ доступна для: "Зарегистрирован", "Дисквалифицирован", "Неявка"
- Дисквалификация только для зарегистрированных участников
- Автоматическая отметка неявки через 30 минут после начала мероприятия

## Мониторинг и логирование

### Системные логи

1. **Nginx**
   - access.log - Логи доступа
   - error.log - Ошибки веб-сервера

2. **PHP**
   - error.log - Ошибки PHP
   - slow.log - Медленные запросы

3. **PostgreSQL**
   - Логи запросов
   - Ошибки базы данных

### Приложение

1. **Безопасность**
   - Попытки входа
   - Подозрительная активность
   - Изменения критичных данных

2. **Мониторинг**
   - Производительность
   - Использование ресурсов
   - Статус сервисов

## Резервное копирование

### Ежедневные задачи
- Резервное копирование PostgreSQL (2:00)
- Резервное копирование Redis (3:00)
- Очистка старых данных (5:00)

### Еженедельные задачи
- Очистка логов (воскресенье, 4:00)
- Проверка целостности данных

### Ежегодные задачи
- Создание годовых архивов (1 января)
- Обновление системы безопасности

## Автоматизация

### Cron-задачи

1. **Ежечасно**
   - Автозакрытие регистрации
   - Автоотметка неявки

2. **Ежедневно**
   - Резервное копирование
   - Проверка безопасности
   - Очистка временных файлов

3. **Еженедельно**
   - Ротация логов
   - Обновление статистики

## Безопасность

### Аутентификация
- Хеширование паролей (password_hash)
- Токены сброса паролей
- Ограничение попыток входа

### Авторизация
- Проверка ролей на каждой странице
- CSRF защита для POST запросов
- Валидация входных данных

### Данные
- Prepared statements для SQL
- Санитизация пользовательского ввода
- Защита от XSS атак 