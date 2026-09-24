# Апгрейд: PHP 8.3 → 8.5, затем Symfony 7.0 → 8.0

Роадмап (много деплоев). Каждая фаза при взятии разворачивается в свой `docs/plans/upgrade-<phase>.md`
со своей веткой, тестами и деплоем-тегом. Здесь — карта, порядок, авторитетные данные (composer),
чек-листы и риски. Данные собраны 2026-09-24 (`composer why-not` / `outdated -D` в контейнере
`manager_php-fpm`).

## Текущее состояние (факт)
- Symfony компоненты прибиты к **7.0.\*** (lock 7.0.10). **7.0 — EOL** (нет security-патчей).
- PHP: контейнеры **8.3** (`Dockerfile` php:8.3-fpm, `docker/php-cli` и `docker/supervisor`
  php:8.3-cli-alpine3.19; `docker/php-fpm` php:8.3-fpm). Composer `php: >=8.2`. **Хост уже 8.5.5.**
- CI (`.github/workflows/{ci,tests,deploy}.yml`) гоняет через docker-compose → **версия PHP в CI = базовый
  образ** (менять образ = менять CI).
- Расширения (осн. `Dockerfile`): intl, opcache, zip, gd + pecl apcu, redis, memcached, amqp (+ gmp/pdo_pgsql
  — свериться по факту; gmp нужен web-push).

## Жёсткие ограничения (подтверждено `composer why-not framework-bundle 8.0.*`)
1. **7.0 → 8.0 напрямую нельзя.** Symfony 8 конфликтует с `symfony/* < 7.4` (security-csrf, serializer,
   translation, messenger, form, mime, console). Правило: доехать до **7.4 (LTS)** с нулём deprecations, потом 8.0.
   Лесенка: `7.0 → 7.1 → 7.2 → 7.3 → 7.4 → 8.0`.
2. **Symfony 8.0 требует PHP ≥ 8.4.** Ставим 8.5.
3. **Dev-тулинг не поддерживает 8.5** (потому cs-fixer/phpstan сейчас и гоняются в 8.3-контейнере):
   `friendsofphp/php-cs-fixer` 3.57 → **3.95** (minor, но обязателен для 8.5), `phpstan/phpstan` 1.11 →
   **2.2** (MAJOR: новый конфиг/levels), `phpunit/phpunit` 9.6 (EOL, на 8.5 сыпет
   `ReflectionProperty::setAccessible deprecated since 8.5`, 334×) → 10/11/**12**. Значит **бамп PHP 8.5
   тянет апгрейд dev-тулинга ПЕРВЫМ**.

## Порядок (по просьбе: сначала PHP 8.5, потом Symfony 8)
Смысловая последовательность — фазы, каждая своя ветка/тег:

- **Фаза 0 — Подготовка** (на 7.0): вычистить висящие deprecations + прибить «дикие» констрейнты. Без подъёма мажоров.
- **Фаза 1 — PHP 8.5 рантайм** (остаёмся на Symfony 7.0): апгрейд dev-тулинга (cs-fixer/phpstan/phpunit)
  до 8.5-совместимых, смена Docker-базы 8.3 → 8.5, CI. Symfony 7.0 на 8.5 РАБОТАЕТ (хост это уже
  доказывает), но 7.0 EOL — долго на нём не сидим, сразу Фаза 2.
- **Фаза 2 — Лесенка Symfony 7.0 → 7.4** (на PHP 8.5): по минору, вычистка deprecations под ноль на 7.4.
- **Фаза 3 — Symfony 8.0** (PHP 8.5 уже есть): подъём `symfony/* : 8.0.*` + обязательные major-бампы бандлов.

Альтернатива (если Фаза 1 на EOL-7.0 не устраивает из-за security): сделать Фазу 2 (ладдер до 7.4) ДО
смены PHP-базы, а PHP 8.5 — между 7.4 и 8.0. Тогда меньше времени на непатченном 7.0, но «PHP 8.5» приходит
позже. **Рекомендую именно этот безопасный вариант, если 7.0-на-8.5 не нужен как отдельная веха.** Обе
последовательности приводят к «PHP 8.5, затем Symfony 8».

---

## Фаза 0 — Подготовка (ветка `upgrade-0-prep`, на 7.0)

**Rector — основной инструмент апгрейда (заводим ЗДЕСЬ, используем во всех фазах).**
- Поставить `rector/rector` (dev), создать `rector.php`: пути `app/src` + `app/tests`, `phpVersion` = текущий
  таргет фазы, кэш. Наборы подключать ПО ФАЗАМ, не всё сразу:
  - Фаза 0: `LevelSetList::UP_TO_PHP_83` (текущий рантайм) + базовые code-quality — снять то, что и так висит.
  - Фаза 1: `PHPUnitSetList` (аннотации→атрибуты `#[Test]`/`#[DataProvider]`, config-миграция) + `UP_TO_PHP_85`.
  - Фаза 2: `SymfonyLevelSetList::UP_TO_SYMFONY_74` (по мере подъёма миноров) — автоправка deprecations.
  - Фаза 3: `SymfonyLevelSetList::UP_TO_SYMFONY_80`.
- Гонять в контейнере (Rector исполняется на рантайм-PHP фазы), процесс: `rector process --dry-run` → ревью
  диффа → `rector process` → `./run check` → коммит. **Не применять вслепую** — Rector иногда ломает семантику
  (особенно кастомные VO/домен), диффы читать. Добавить `rector` в `./run` как команду.
- Что Rector НЕ закрывает и делаем руками: конфиги (`config/packages/*`, Docker, CI), major-бампы бандлов
  (lexik v3/gesdinet v2 — их миграции ручные), рантайм-логика.

**УТОЧНЕНО трассировкой (2026-09-24): крупные deprecations — ВЕНДОРНЫЕ, не наш код → чинятся подъёмом
Symfony (Фаза 2), НЕ в Фазе 0.** В Фазе 0 нашего-кода вычищать почти нечего.
- `mb_strlen()/mb_detect_encoding(null)` (~568× unit+functional) — зовут **symfony/form** трансформеры
  (Percent/Number → поля влажности/температур), symfony/string, symfony/translation. Наш `src` чист
  (mb_detect_encoding нет, mb_strlen под гвардами). → уйдёт с Symfony 7.4.
- twig `Environment::mergeGlobals` (199×) — зовёт **symfony/twig-bridge** (`Form/TwigRendererEngine`) при
  рендере форм. → уйдёт с Symfony 7.4 (twig-bridge/twig-bump).

Реально в Фазе 0 (наш код/конфиг):
- doctrine-bundle 2.12: `doctrine.orm.controller_resolver.auto_mapping` — задать явно `true` в
  `config/packages/doctrine.yaml`.
- doctrine/orm ClassMetadataFactory (issue 8893) — разовый ворнинг, оценить.
- Прибить дикие констрейнты: `lexik/jwt-authentication-bundle: "*"` → зафиксировать текущую (`^2.21`);
  `symfony/symfony: "*"` в polyfill-провайде — проверить, не тянет ли лишнее.
- Разобрать дубль PDF: `mpdf ^8.2` + `tcpdf ^6.7` одновременно — выяснить, кто что использует, по возможности
  оставить одну.
- `qossmic/deptrac-shim` — **abandoned**, мигрировать на `qossmic/deptrac`.
- Включить `SYMFONY_DEPRECATIONS_HELPER` в тестах (phpunit.xml.dist) — чтобы ступени ловили регресс по числу.
- **Verify:** `./run check` зелёный, число deprecations в отчёте тестов зафиксировано (baseline).

## Фаза 1 — PHP 8.5 рантайм (ветка `upgrade-1-php85`)
Dev-тулинг (обязательно для 8.5):
- `friendsofphp/php-cs-fixer` `^3.57` → `^3.95`; прогнать `style:fix`, разобрать новые правила.
- `phpstan/phpstan` `^1.11` → `^2.2` (MAJOR): обновить `phpstan.neon` (новый формат levels/ignore),
  разобрать всплывшие ошибки уровня 6.
- `phpunit/phpunit` `^9.6` → `^11`/`^12` + `symfony/phpunit-bridge` `^8` (MAJOR): миграция конфига
  phpunit.xml (schema 10+), возможные правки аннотаций→атрибутов (`@test`, `@dataProvider` → `#[Test]`,
  `#[DataProvider]`), `setAccessible`-депрекейшн уйдёт. **Крупный объём — оценить как под-план.**
- Docker: базы `Dockerfile`, `docker/php-fpm/Dockerfile`, `docker/php-cli/Dockerfile`,
  `docker/supervisor/Dockerfile` → php:8.5-*. Фактический набор расширений (осн. Dockerfile):
  **intl, opcache, zip, pdo_pgsql, bcmath, sockets, gd** (docker-php-ext) + **apcu, redis, memcached, amqp**
  (pecl). gmp НЕ ставится — свериться, работает ли web-push на 8.5 без gmp (bcmath-фолбэк). Проверить сборку
  pecl-расширений под 8.5; по возможности перейти на `mlocati/docker-php-extension-installer`.
- `docker-compose.test.yml` — та же 8.5-база (CI поедет автоматически).
- composer.json `php: >=8.2` → `>=8.4` (или `>=8.5`), пересобрать образы, `./run check` зелёный НА 8.5.
- **Verify:** весь стек в контейнере на 8.5, `./run check` зелёный, браузер-смоук.

## Фаза 2 — Лесенка Symfony 7.1 → 7.4 (ветки `upgrade-2-syN`, по минору)
**Master-рычаг — Flex-пин `extra.symfony.require` в composer.json** (сейчас `"7.0.*"`), он жёстко держит
ВСЕ symfony/* (включая транзитивные config/http-kernel/…) на миноре. Подтверждено: dry-run подъёма только
`framework-bundle:7.4.*` падает («Restricting packages listed in symfony/symfony to 7.0.*» → конфликт с
config ^7.4). На каждой ступени: `extra.symfony.require` `7.N.*` + явные `symfony/* : 7.N.*` в require, затем
`composer update "symfony/*" --with-all-dependencies`, `bin/console` без депрекейшенов, `./run check`, смоук, деплой-тег.
(`"symfony/symfony": "*"` в conflict-блоке — норма Flex, не трогать.)
Полезное по ходу:
- **7.1**: `#[IsCsrfTokenValid]` — заменить ручной CSRF-трейт (из security-Волны 2) на атрибут.
- **7.2**: улучшения CSRF (stateless) — оценить для форм/удалений.
- **7.3–7.4**: рубеж 7.4 (LTS) — **ВСЕ оставшиеся deprecations под ноль** перед 8.0.
- Попутные dev-бандлы на 7.4-совместимые (доступны): `doctrine/doctrine-bundle` 2.12 → 2.19,
  `doctrine/orm` 3.1 → 3.7, `symfony/maker-bundle` 1.58 → 1.68, `twig/*` 3.14 → 3.29,
  `symfony/webpack-encore-bundle` 2.2 → 2.4, ux-* 2.22 → 2.36.

## Фаза 3 — Symfony 8.0 (ветка `upgrade-3-sy8`)
- `symfony/* : 8.0.*`, `composer update --with-all-dependencies` (PHP 8.5 уже удовлетворяет ≥8.4).
- **Обязательные для Sy8 major/minor-бампы бандлов** (текущие версии кап на Sy7 — блокеры по `why-not`):
  - `lexik/jwt-authentication-bundle` v2.21 → **v3.2** (MAJOR, auth — аккуратно, проверить конфиг ключей).
  - `gesdinet/jwt-refresh-token-bundle` v1.3 → **v2.2** (MAJOR).
  - `symfony/monolog-bundle` 3.10 → **4.1** (MAJOR).
  - `twig/extra-bundle` 3.13 → 3.29, `dama/doctrine-test-bundle` 8.0 → 8.6, `liip/test-fixtures-bundle`
    3.0 → 3.9, `doctrine/doctrine-migrations-bundle` 3.3 → 3.7 (все имеют Sy8-совместимые релизы).
  - `symfony/phpunit-bridge` → 8.x (если не сделано в Фазе 1).
- Прогон миграций/`.env`/VAPID — как обычно деплой.
- **Verify:** `./run check` на 8.5+Sy8, полный браузер-смоук (формы, live-components, JWT-API, telegram-вебхук,
  генерация docx/xlsx, web-push).

---

## Матрица зависимостей (composer outdated -D, 2026-09-24)
| Пакет | Сейчас | Доступно | Для чего | Когда |
|---|---|---|---|---|
| symfony/* | 7.0.x | 7.4.19 / 8.0 | фреймворк | Фаза 2 (7.4), Фаза 3 (8.0) |
| php-cs-fixer | 3.57 | 3.95 | стиль на 8.5 | Фаза 1 |
| phpstan | 1.11 | 2.2 (MAJOR) | статика на 8.5 | Фаза 1 |
| phpunit | 9.6 | 12.5 (3 MAJOR) | тесты на 8.5 | Фаза 1 (крупно) |
| lexik/jwt | 2.21 | 3.2 (MAJOR) | **блокер Sy8** | Фаза 3 |
| gesdinet/jwt-refresh | 1.3 | 2.2 (MAJOR) | **блокер Sy8** | Фаза 3 |
| monolog-bundle | 3.10 | 4.1 (MAJOR) | **блокер Sy8** | Фаза 3 |
| doctrine-bundle | 2.12 | 2.19 | Sy8 | Фаза 2/3 |
| doctrine/orm | 3.1 | 3.7 | — | Фаза 2 |
| doctrine/dbal | 3.8 | 4.4 (MAJOR) | **опционально** (ORM3/bundle2.19 держат DBAL 3 и 4) | отдельная задача, можно ОТЛОЖИТЬ |
| phpoffice/phpspreadsheet | 3.6 | 5.10 (2 MAJOR) | XlsxRenderer/Proposals tkp | **отдельная задача**, не блокер Sy8 |
| phpoffice/phpword | 1.2 | 1.x | движок docx | не блокер |
| minishlink/web-push | 10 | 11 (MAJOR) | web-push | отдельно, не блокер Sy8 |
| webmozart/assert | 1.11 | 2.4 (MAJOR) | — | не Sy-завязан, опционально |
| nesbot/carbon | 3.8 | 3.14 | — | Фаза 2 |
| elasticsearch | 9.0 | 9.5 | поиск | не блокер |
| longman/telegram-bot | 0.83.1 | — (одна версия) | старьё, НЕ блокер: `php ^8.1` пускает 8.5, не Symfony-бандл | смоук вебхука |
| irazasyed/telegram-bot-sdk | 3.15 | 3.16 | telegram | проверить |

**Правило:** любой «блокер Sy8» без совместимого релиза = стоп Фазы 3 (у lexik/gesdinet/monolog релизы ЕСТЬ).
Опциональные мажоры (DBAL 4, phpspreadsheet 5, web-push 11, webmozart 2) — **отдельными задачами ПОСЛЕ Sy8**,
чтобы не раздувать критический путь.

## Отдельные major-миграции (после/параллельно, НЕ на критическом пути Sy8)
- **DBAL 3 → 4**: breaking (типы, Result API). ORM 3 и doctrine-bundle 2.19 работают с DBAL 3 → можно жить
  на DBAL 3 и после Sy8. Своя ветка, свой прогон миграций/DBAL-типов (у нас кастомные JSON-типы VO).
- **PHPUnit 9 → 12**: если не осилить в Фазе 1, минимальный вариант — phpunit 10/11 для чистого 8.5, а 12 —
  потом. Массовая правка тестов (атрибуты).
- **phpspreadsheet 3 → 5**: затрагивает `XlsxTemplateRenderer` + Proposals `tkp_template.xlsx`. Свои тесты.

## Риски
- Объём deprecations 7.0→7.4 (4 минора накопления) + PHP 8.5-строгости.
- Крупный dev-тулинг апгрейд в Фазе 1 (phpstan v2, phpunit 10+) может дать много правок — заложить время.
- Auth-бандлы (lexik v3, gesdinet v2) на Фазе 3 — риск конфига/ключей, тщательный смоук JWT-API + refresh.
- `longman/telegram-bot 0.83.1` — старьё (одна версия), но `php ^8.1` пускает 8.5 и это не Symfony-бандл →
  НЕ блокер апгрейда; риск лишь в самой либе на новом PHP — прогнать смоук telegram-вебхука на 8.5.
- Docker-база 8.5: наличие всех pecl-расширений (amqp/memcached/apcu/redis) под 8.5 — свериться заранее.
- SW/PWA/фронт (JS/CSS) — независимы, апгрейд их не трогает.

## Деплой-стратегия
- **Не big-bang.** По фазам, каждая — своя ветка + тег `v*` + прод-смоук. Минимум-веха: 7.4, затем 8.0.
- **Security сначала:** незакрытые security-фиксы (Волны 1–3 из аудита) катим на 7.0 ДО апгрейда — они срочные
  и мелкие; ручной CSRF-трейт меняем на `#[IsCsrfTokenValid]` на 7.1.
- Прод: деплой = тег на tip main (см. [[reference_prod_messenger_redis_ops]]); Redis-группы переживают деплой.

Связано: [[project_security_audit_2026_09]] (security на 7.0 первым), [[reference_test_run_env]] (проверки),
[[reference_prod_messenger_redis_ops]] (деплой по тегам).
