# Апгрейд: Symfony 7.0 → 8.0 + PHP 8.3 → 8.5

Роадмап-план (не один деплой). Каждая ступень при взятии разворачивается в свой
`docs/plans/upgrade-sy7N.md` со своей веткой, тестами и деплоем. Здесь — общая карта, порядок,
чек-листы и риски.

## Зачем
- Symfony прибит к **7.0.\***, а 7.0 — **EOL** (поддержка кончилась ~07.2024): security-патчей нет.
  Работать на непатченном фреймворке — само по себе security-риск. Апгрейд = фундаментальный
  hardening (не «квик-вин», но обязательный).
- PHP: рантайм **8.3.14** (контейнер), хост уже 8.5; composer `php: >=8.2`.

## Жёсткое ограничение пути
**Прыгнуть 7.0 → 8.0 напрямую нельзя.** Правило Symfony: доехать до последнего минора **7.4 (LTS)**
с НУЛЁМ deprecations, затем 8.0. Лесенка:

`7.0 → 7.1 → 7.2 → 7.3 → 7.4 (LTS) → 8.0`

На каждой ступени: поднять констрейнты `symfony/*` на минор, `composer update "symfony/*" --with-all-dependencies`,
вычистить deprecations, `./run check` зелёный, браузерный смоук, деплой ступени тегом.

PHP: Symfony **8.0 требует PHP 8.4+**; ставим 8.5. Рантайм 8.3 → поднять базовые образы к 8.0-ступени.

## Ступени (черновой объём)

### 0. Подготовка (до подъёма версий)
- Вычистить УЖЕ висящие deprecations (видны в тестах, не привязаны к минору):
  - twig `Environment::mergeGlobals` (twig 3.14) — найти вызов, заменить.
  - doctrine-bundle 2.12: `doctrine.orm.controller_resolver.auto_mapping` — задать явно `true`.
  - `mb_detect_encoding()/mb_strlen(null)` — чинить передачу null (много мест в тестах/сервисах).
- Прибить `lexik/jwt-authentication-bundle` к версии (сейчас `*` — опасно).
- Разобрать дубль PDF-либ: `mpdf ^8.2` + `tcpdf ^6.7` одновременно — оставить одну, если возможно.
- Включить Symfony deprecation-репортинг в тестах (`SYMFONY_DEPRECATIONS_HELPER`), чтобы ступени
  ловили регресс.

### 1–4. Минорки 7.1 → 7.4
Каждая: `symfony/* : 7.N.*`, `composer update`, прогнать `bin/console` без депрекейшенов,
`./run check`, смоук, деплой. По ходу забирать полезное:
- **7.1**: `#[IsCsrfTokenValid]` — заменить ручной CSRF-трейт из Волны 2 на атрибут (тривиально).
- **7.2**: улучшения CSRF (в т.ч. stateless-варианты) — оценить для наших форм/удалений.
- **7.4 (LTS)**: рубеж — здесь чиним ВСЕ оставшиеся deprecations под ноль перед 8.0.

### 5. Symfony 8.0 + PHP 8.5
- `symfony/* : 8.0.*`; `php: >=8.4` (ставим 8.5).
- Docker: базовые образы `docker/php-fpm`, `docker/php-cli`, `docker/supervisor` → PHP 8.5;
  проверить наличие расширений на 8.5-базе (`gmp`, `redis`, `iconv`, `ctype`, intl и пр. — см.
  install-php-extensions).
- CI (`.github/workflows/*`): PHP-версия сборки → 8.5.
- Прогон миграций/`.env`/VAPID — как обычно деплой пишет сам.

## Чек-лист совместимости зависимостей (проверить версии под Symfony 8 ДО подъёма)
Историч. тормоза — проверять первыми:
- `gesdinet/jwt-refresh-token-bundle` (у нас ^1.3 — старый; есть ли Sy8-совместимая?)
- `lexik/jwt-authentication-bundle` (прибить + Sy8)
- `symfony/ux-live-component`, `symfony/ux-twig-component` (2.x — покрытие Sy8)
- `symfony/webpack-encore-bundle`, `oneup/flysystem-bundle`, `symfony/monolog-bundle`
- `longman/telegram-bot ^0.83` (старьё), `irazasyed/telegram-bot-sdk ^3.15`
- `minishlink/web-push ^10`, `elasticsearch/elasticsearch ^9`, `phpoffice/phpspreadsheet ^3.6`,
  `doctrine/orm ^3`, `doctrine/dbal ^3` (рассмотреть DBAL 4 отдельно), `nesbot/carbon ^3`
Любой без Sy8-версии = блокер ступени 8.0 (ждать релиз либо искать замену).

## Деплой-стратегия
- **Не big-bang.** Деплой по ступеням (минимум: 7.4, затем 8.0; лучше — каждый минор). Каждая
  ступень — своя ветка, свой тег `v*`, прод-смоук после.
- Порядок относительно security: **security-фиксы (Волны 1–3) катим ПЕРВЫМИ на 7.0** — они срочные
  и мелкие; апгрейд не должен их задерживать. Ручной CSRF-трейт Волны 2 позже (на 7.1) меняем на
  `#[IsCsrfTokenValid]`.

## Риски
- Объём deprecations между 7.0 и 7.4 (4 минора накопления).
- Отставание бандлов (gesdinet/lexik/ux/telegram) под Sy8 — возможен вынужденный простой на ступени.
- Рантайм-изменения на PHP 8.5 (строгость типов, `mb_*`(null) уже фейлит) + смена Docker-базы.
- SW/PWA апгрейд не трогает (клиентская часть независима).

Связано: [[project_security_audit_2026_09]] (сначала security на 7.0), [[reference_test_run_env]]
(как гонять проверки), [[reference_prod_messenger_redis_ops]] (деплой = тег на tip main, по ступеням).

## ПРОГРЕСС Фазы 2 — PHP 8.5 (ветка upgrade-2-php85, от upgrade-1-sy71, ещё на контейнере 8.3)

Порядок фактический — вариант A (лесенка 7.0→7.4 сделана ПЕРВОЙ = Фаза 1), тут Фаза 2 = тулинг под 8.5.

Сделано (ещё под PHP 8.3-контейнером, до смены базового образа):
- **php-cs-fixer 3.95** (process уже 7.4 — встал), **phpstan 1→2** (level 6): убран `checkGenericClassInNonGenericObjectType`,
  добавлен `ignoreErrors: missingType.generics`; 148 существующих находок v2 в `phpstan-baseline.neon` (разобрать
  Rector'ом отдельной задачей — dead-code/quality). Style/phpstan/unit зелёные.
- **PHPUnit 9→11**: `phpunit.xml.dist` переписан под схему 11 — `<extensions><bootstrap class=…>` вместо
  `<extension>`/`<listeners>` (иначе DAMA и SymfonyExtension НЕ регистрируются → нет отката транзакций → data leak
  между тестами); `<coverage>`→`<source>`; снят `convertDeprecationsToExceptions`, `SYMFONY_PHPUNIT_*`. Локальный
  gitignored `phpunit.xml` держать в синхроне с `.dist` (контейнер шадовит его). dama 8.6 + liip 3.9.
- `ReportWorkflowTest::status()`→`reportStatus()` (PHPUnit 11 сделал `TestCase::status()` final).
- **РЕГРЕССИЯ doctrine-bundle 2.13+ (важно для Фазы 3):** `composer update -W` при установке dev-тулинга случайно
  затянул doctrine-bundle 2.12→2.19. На 2.13+ `find()` уже-managed сущности приводит к повторной гидрации, и
  Doctrine пытается заново присвоить **readonly-свойство-ОБЪЕКТ** (`Report::$id` = Uuid VO, `StoredFile::$createdAt`
  = DateTimeImmutable) — новый инстанс `!==` старому → `LogicException: Attempting to change readonly property`
  (27 errors + 13 failures в функциональных). orm 3.1.3 и dbal 3.10 НЕ виноваты (бисект подтвердил). **Решение
  Фазы 2:** пин `doctrine/doctrine-bundle: 2.12.*` (как на main/phase-1, где зелено). **Фаза 3 (Sy8 требует
  bundle 2.19+):** снять пин и разобрать причину повторной гидрации (кандидаты: смена дефолта identity-map/refresh
  в bundle 2.13+; либо чинить readonly-VO ↔ гидрация, либо `$em->clear()` в тестах stage→promote).
- Тестовую БД пересоздавать миграциями (`schema:drop --full-database` + `migrations:migrate`) — нужны кастомные
  функциональные индексы (`uniq_coating_color_name_hex` с `lower(name)`), `schema:create` их пропустит.

`./run check` (style/phpstan/unit/functional) — ЗЕЛЁНЫЙ на контейнере 8.3. FK-шум в tearDown отдельных тестов
(user_channel) безобиден — DAMA всё равно откатывает. 2 deprecations — вендорные (гейт max[self]=0 прошёл).

Docker-база 8.3→8.5 (сделано, все 4 Dockerfile'а собираются, расширения на месте, `./run check` ЗЕЛЁНЫЙ под PHP 8.5.10):
- FROM: Debian `php:8.3-fpm`→`php:8.5-fpm` (`Dockerfile`, `docker/php-fpm`); Alpine `php:8.3-cli-alpine3.19`→`php:8.5-cli-alpine3.24`
  (`docker/php-cli`, `docker/supervisor`).
- **Debian-расширения переведены с ручного `docker-php-ext-install`+`pecl` на `install-php-extensions`** (как в alpine-образах):
  ручной путь ломался на 8.5/trixie — `pdo` стал частью ядра → `make install-modules`: `cp: cannot stat 'modules/*'`. Теперь
  единый `install-php-extensions intl opcache zip pdo_pgsql bcmath sockets gd apcu redis memcached amqp` во всех образах.
- `composer:2.7.2`→`composer:2.8` (2.7.2 сыпал `E_STRICT deprecated` на 8.5; сборка шла, но грязно).
- composer.json: `php: >=8.4`; **`config.platform.php: 8.5.10`** (лок резолвится под целевой рантайм, не под контейнер).
- **Полный `composer update -W` под платформу 8.5** (точечного не хватило — платформа-бамп вскрыл капы `~8.4.0`):
  подняты mpdf 8.2.5→8.3.1, phpspreadsheet 3.6→3.10, tcpdf 6.7→6.11, lcobucci/clock→3.6, lcobucci/jwt→5.6, webmozart/assert
  1.11→1.12 (новые стабы → +4 phpstan-находки, впитаны в baseline, итого 152), stimulus-bundle 2.36→3.5.1 (транзитив, JS уже 3.x).
  Пины УДЕРЖАНЫ: twig/twig 3.28 (<3.29), symfony framework-bundle 7.4.19 (flex), doctrine-bundle 2.12.1, lexik 2.21.
- CI правок НЕ требует: версия PHP = FROM Dockerfile'а (tests.yml строит test_php-cli из `docker/php-cli`; deploy.yml собирает
  `docker/${service}/Dockerfile`). root `Dockerfile` CI не собирает (правил для консистентности, не билдил).

ФАЗА 2 ЗАВЕРШЕНА (gate зелёный под 8.5). Осталось до коммита: ручной фронт-смоук (yarn build + браузер — stimulus-bundle 3.x,
npm-депы не трогались) и прод-смоук после деплоя. НЕ закоммичено (жду апрув). Дальше — Фаза 3 (Symfony 8.0).
