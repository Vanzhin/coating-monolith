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
