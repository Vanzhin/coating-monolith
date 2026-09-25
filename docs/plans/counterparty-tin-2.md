# Деплой 2: ИНН контрагента — строго NOT NULL + полный unique

> Сосед: `docs/plans/counterparty-tin-1.md` (Деплой 1 — VO Tin, nullable-колонка, обязателен для новых, partial-unique). Этот деплой запускать ТОЛЬКО когда у ВСЕХ существующих контрагентов ИНН заполнен (иначе миграция упадёт).

**Цель:** зафиксировать инвариант «у каждого контрагента есть уникальный ИНН» на уровне БД: колонка `tin` → `NOT NULL`, unique-индекс → полный (без `WHERE tin IS NOT NULL`).

**Предусловие (обязательно проверить перед деплоем):**
- `SELECT count(*) FROM reports_counterparty WHERE tin IS NULL;` → должно быть `0`. Если нет — сперва добить ИНН в админке, деплой не запускать.

## Задачи

### T1. Бэкфилл-гейт (проверка)
- [ ] Ручная/скриптовая проверка на проде: нет строк с `tin IS NULL`. Иначе — стоп.

### T2. Миграция: NOT NULL + полный unique
**Файл:** новая `app/src/Shared/Infrastructure/Database/Migrations/Version<ts>.php`, идемпотентная:
- Предохранитель: если есть `tin IS NULL` — кинуть внятную ошибку миграции (не молча).
- `DROP INDEX IF EXISTS uniq_reports_counterparty_tin;` (partial) → `CREATE UNIQUE INDEX IF NOT EXISTS uniq_reports_counterparty_tin ON reports_counterparty (tin);` (полный).
- `ALTER TABLE reports_counterparty ALTER COLUMN tin SET NOT NULL;`
- down: обратно (partial + drop NOT NULL).

### T3. ORM mapping
**Файл:** `Counterparty.Counterparty.orm.xml` — `<field name="tin" ... nullable="false"/>` (снять nullable).

### T4. Домен
**Файл:** `Counterparty.php` — поле `private string $tin;` (снять `?` и `= null`), `getTin(): string`. Убедиться, что legacy-путь с null больше не нужен (все заполнены). Гидрация Doctrine теперь всегда с непустым tin.

### T5. Гейты
- [ ] `./run check` зелёный. Функц. тесты уже требуют ИНН (с Деплоя 1) — падать не должны.
- [ ] Прод: прогнать миграцию сразу после деплоя.
