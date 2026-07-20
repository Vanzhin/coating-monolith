# Правила работы с кодом в этом проекте

Прочти ДО того, как редактировать код. Эти правила — итог реальных разборов багов, а не теории. Нарушать их можно, но только с явным согласием пользователя.

## Главные принципы

**1. Самая важная логика — в доменных объектах.** Бизнес-правила, инварианты, расчёты, физика предметной области — всё это живёт в `{Context}/Domain/` (агрегаты, VO, доменные сервисы). Не в хендлерах, не в мапперах, не в контроллерах, не в Twig, не в JS. Если правило размазано по слоям — это бага архитектуры, а не «удобство». Application/Infrastructure только оркеструют и адаптируют, домен принимает решения.

**2. Бэк — единственный источник истины для валидации.** Любая бизнес-проверка живёт на сервере и при нарушении возвращает либо OK, либо ошибку, которую фронт рендерит. Дубликаты на фронте допустимы только ради UX (мгновенная подсказка), но при отсутствии JS форма обязана работать корректно через серверную валидацию. Фронт никогда не решает «можно ли сохранить» — только показывает то, что вернул бэк.


## Главное правило

**НЕ РЕАЛИЗУЙ БЕЗ ОБСУЖДЕНИЯ.** Перед любой реализацией обсуди детали с пользователем и получи подтверждение.

## Стиль общения

- **Никаких эмоджи** в ответах. Никаких ✅/❌/⚠️/🚀, ни декоративных, ни чек-маркеров «✓ сделано». Статусы передавать словами («готово», «накатил», «провалилось»). Мат допустим.
- **Кратко и по делу.** Не писать лишнего, не растягивать ответы, не повторять очевидное. Отвечать по-деловому — без вступлений, без украшательств.

## Режим работы: Plan → Code

При получении новой задачи:
1. **Автоматически входи в режим планирования** (`/plan`)
2. Изучи задачу, задай вопросы, предложи подход
3. Когда планирование завершено — **спроси разрешение**: "Переходим к коду?"
4. После подтверждения — переключись в режим кода (`/code`) и реализуй
5. **Сохраняй план** в `docs/plans/{название-задачи}.md` — чтобы можно было отложить и вернуться позже

### Пошаговая реализация

Реализацию делать **по шагам**. После каждого шага — показать результат и спросить, продолжать ли. **НЕ лететь нонстопом** через весь план.

Пользователь — эксперт проекта, знает тонкости. Claude их не знает, любое расхождение с ожиданиями нужно ловить на первом же шаге, а не в конце.

### Строго по плану

План — источник правды. Если видишь расхождение плана с реальностью (в коде нет нужного, логика оказалась другой, и т.п.):

1. Озвучить проблему пользователю.
2. Спросить: «нужно ли обновить план?».
3. Обновить план, если да.
4. Реализовывать строго по обновлённому плану.

**Никогда** не менять план самостоятельно и не отклоняться от написанного.

### Многоэтапные задачи — отдельные планы

Если задача требует более одного деплоя (например, «наполнить новый источник данных» → «переключить чтение»), **не** описывать её одним планом с разделами «Деплой 1 / Деплой 2». Создавать отдельные файлы планов — по одному на деплой:

- `docs/plans/название-задачи-1.md`
- `docs/plans/название-задачи-2.md`

Каждый файл — самодостаточный: свой контекст, свои развилки, свой список файлов и тестов, перекрёстная ссылка на соседний план. Это позволяет работать с каждым деплоем как с независимой задачей: отдать в работу один план без остального контекста, отдельно отслеживать статус, независимо пересматривать развилки.

## Документация — читать перед работой

**Правило: в каждом каталоге, куда заходишь, ищи `README.md` (или аналогичный `.md`-файл документации — `CLAUDE.md`, `docs/*.md`) и читай его перед тем как работать с кодом этого каталога.** Это действует на всех уровнях без исключений — от корня репо до самого вложенного каталога. Если документа нет — пропускай и иди дальше. Если есть — читай **до** внесения изменений, а не после.

Документ может содержать архитектурные решения, соглашения, потоки, ограничения — то, чего нет в самом коде и что не выводится из него. Пропускать уровни нельзя. На каждом уровне могут быть правила, отсутствующие на других. При сомнении — читать, а не гадать.

## Архитектура

DDD + Hexagonal. Каждый bounded context (`Coatings`, `Documents`, `Notifications`, `Proposals`, `Users`) имеет три слоя:

```
app/src/{Context}/
  ├─ Domain/                — бизнес-правила, инварианты, агрегаты, VO
  │   ├─ Aggregate/{Name}/  — корень агрегата + его VO и доменные сервисы
  │   ├─ Factory/           — конструкторы агрегатов
  │   ├─ Repository/        — ИНТЕРФЕЙСЫ репозиториев
  │   └─ Service/           — доменные сервисы (если не помещаются в агрегат)
  ├─ Application/           — оркестрация
  │   ├─ DTO/               — DTO для команд/запросов (не для домена)
  │   └─ UseCase/
  │       ├─ Command/       — write-side: handler + command + result
  │       └─ Query/         — read-side
  └─ Infrastructure/        — всё внешнее
      ├─ Controller/        — Symfony controllers (тонкие)
      ├─ Database/          — Doctrine ORM XML, DBAL types
      ├─ Mapper/            — form/JSON ↔ DTO (только shape, без бизнес-логики)
      ├─ Repository/        — Doctrine-реализации Repository из Domain
      └─ Api/, Search/      — внешние интеграции
```

Общие вещи — в `app/src/Shared/` с теми же тремя слоями.

## Где живёт ЧТО

### Domain (`{Context}/Domain/`)

Тут и только тут живут **инварианты** — правила, без которых объект не имеет смысла:

- Самый узкий VO, к которому относится правило. Пример: «время не может быть ≤ 0» → `TimeAtTemperature::__construct`, а не `DryingTimeSeries` и тем более не маппер.
- Если правило про связь нескольких полей одного VO — конструктор того VO. Пример: «точки серии монотонно убывают по температуре» → `DryingTimeSeries::validatePointsConsistency`.
- Если правило про несколько VO внутри агрегата — сеттер агрегата или метод `validate()`/доменный сервис. Пример: ключи дерева перекрытия должны соответствовать enum-ам → `CoatingRecoatingTreeValidator`, вызывается из `Coating::setMinRecoatingInterval`.

Инвариант кидает `App\Shared\Infrastructure\Exception\AppException` с человекочитаемым сообщением и контекстом (например, проблемной температурой). Этот exception превращается в HTTP 422 автоматически и попадает в форму как `<div class="alert alert-danger">{{ error }}</div>`.

VO — `final readonly`. Никаких сеттеров, любая «модификация» возвращает новый инстанс (`withChild`, `withoutChild`).

### Application (`{Context}/Application/`)

- **DTO** — голые data-классы. Без поведения, без валидации.
- **Command/CommandHandler** — оркестрирует: достаёт сущности из репозитория, вызывает методы домена, сохраняет. Никаких `if`-ов с бизнес-смыслом — это работа домена.
- **Query/QueryHandler** — read-side. Часто использует `{Context}/Application/DTO/...Transformer` для конвертации entity → DTO.
- **Сервисы-помощники в Application** (типа `RecoatingTreeBuilder`) — собирают VO из DTO. Они НЕ носители бизнес-правил, они каркас для конструкторов. Если в билдере появляется `if`-про бизнес — это сигнал поднять правило в домен.

### Infrastructure (`{Context}/Infrastructure/`)

- **Controller** — тонкий. Читает POST, отдаёт CommandBus, ловит `\Exception`, рендерит шаблон с `$error`. Никакой бизнес-логики.
- **Mapper** — _pure shape mapping_. Преобразует форму (nested array) ↔ DTO. **Никаких бизнес-фильтров, никаких `throw`-ов про правила домена.** Если нужна форм-специфичная интерпретация («пустая строка max = нет точки»), оформляй явным отдельным методом с понятным именем (`dropZeroDurationPointsRecursively`), а не прячь её внутри общего mapping-метода.
- **Doctrine DBAL Type** для VO, хранимого как JSON: наследует `Doctrine\DBAL\Types\JsonType`, в `convertToPHPValue` зовёт `VO::fromArray`, в `convertToDatabaseValue` — `parent::convertToDatabaseValue($value)` (JsonType сам сериализует через `JsonSerializable`). Регистрируется в `app/config/packages/doctrine.yaml`.

## AppException

```php
throw new AppException('Длительность при +20 °C должна быть положительной.');
```

Сообщение — на русском, для пользователя. Если нужны технические детали для логов — четвёртый аргумент `$log`. Код по умолчанию 422 — менять только если есть причина (например, 404 для not-found).

## Что НЕЛЬЗЯ

- **Не клади бизнес-проверки в Mapper.** Mapper — это инфраструктура, его не должно тошнить от того, что DTO физически невалидна.
- **Не клади бизнес-проверки в CommandHandler через `if` поверх DTO.** Передай DTO в домен и поймай AppException. Handler — оркестр, а не валидатор.
- **Не клади бизнес-проверки в Application-builders.** Если в `RecoatingTreeBuilder` хочется бросить ошибку про правила — правило должно жить в `RecoatingIntervalTree`/`DryingTimeSeries`/`TimeAtTemperature`.
- **Не дублируй HTML между Twig и JS.** Если кнопка/строка генерируется и в шаблоне, и в Stimulus-контроллере — это смерть рефакторинга. Используй `<template>`, частичный fetch или Live Component.
- **Не пиши кастом, если есть встроенное.** Doctrine `JsonType`, Symfony Validator, Doctrine Embeddable, Bridge типы — всегда первый выбор. Свой DBAL Type/Constraint/обёртку только когда встроенное явно не подходит.
- **В Twig только разметка.** Никаких `<style>` и `<script>` блоков внутри шаблонов. CSS — в `app/assets/styles/` (`components/*.css` для переиспользуемых, `admin/*.css` для страничных), подключается через `@import` в `app/assets/styles/app.css`. JS — в `app/assets/controllers/` как Stimulus-контроллеры, автоматически регистрируется через `controllers.json`. После правки CSS/JS — `cd app && yarn dev`. Единственное исключение — critical-path inline в `<head>` в `base.html.twig`: FOUC-safe theme init, root CSS-переменные, ранние bootstrap-конфиги. Всё, что должно выполниться до применения основных CSS/JS, чтобы избежать вспышки не-темы. Такие блоки должны быть маленькими, задокументированными и не расти.
- **Не оставляй мёртвый код.** Удалённые методы убирай вместе с их вызовами (грепай по проекту). Закомментированные строки не оставляй.
- **Не делай `git commit`/`git add`.** Пользователь сам управляет коммитами.

## Шаблон: добавить новое правило

«Минимальная толщина не может быть больше максимальной»:
1. Ищу узкий VO, который этим владеет → `DftRange`.
2. Кидаю `AppException` в его конструкторе, если правило нарушено.
3. Тест: `tests/Unit/Coatings/Domain/Aggregate/Coating/DftRangeTest.php` (зеркаль структуру `src/`).
4. Mapper, Handler, Builder — не трогаю.

Если правило не помещается в один VO — поднимай на сеттер агрегата или в специализированный доменный валидатор (см. `CoatingRecoatingTreeValidator`).

## Шаблон: добавить новое поле в форме покрытия

1. `CoatingDTO` → новое поле.
2. `CoatingMapper::buildCoatingDtoFromInputData` (POST→DTO) и `buildInputDataFromDto` (DTO→форма) — только shape.
3. `CoatingMapper::getValidationCollectionCoating` — `Assert\…` для структурной валидации формы (типы, длины), но не бизнес-правила.
4. `Coating` агрегат → сеттер/конструктор с инвариантом.
5. `CoatingMaker` и `Create/UpdateCoatingCommandHandler` — пробрасывают значение в агрегат.
6. `CoatingDTOTransformer::fromEntity` — обратный путь.
7. Шаблон `form.html.twig` + при необходимости `coating_form_controller.js`.
8. ORM mapping XML `app/src/Coatings/Infrastructure/Database/ORM/Aggregate/Coating.Coating.orm.xml`.
9. Миграция `app/src/Shared/Infrastructure/Database/Migrations/Version*.php` — идемпотентная (`IF NOT EXISTS`, проверки на уже-применённое состояние).

## Тесты

- `tests/Unit/{Context}/...` — зеркалят `src/{Context}/...`.
- Доменные классы — обязательны юнит-тесты конструктора с граничными случаями (то, что кидает AppException → отдельный тест-метод).
- Application-handler-ы — функциональные тесты с реальной БД, не моками (Doctrine не любит моки).
- Mapper-ы — юнит-тесты round-trip (`build → decompose → build` даёт исходную форму).

## Команды

```bash
# Ассеты (после изменения JS/CSS/Twig — обязательно перед проверкой в браузере)
cd app && yarn dev

# Тесты
cd app && vendor/bin/phpunit
cd app && vendor/bin/phpunit tests/Unit/Coatings   # один контекст

# Миграции
cd app && bin/console doctrine:migrations:migrate -n
cd app && bin/console doctrine:migrations:diff     # сгенерировать новую из diff схемы
```

## Перед PR / итогом работы

- Удалить `.DS_Store` и прочий мусор из staged.
- Проверить, что не осталось `dd()`, `var_dump`, закомментированных блоков.
- Прогнать тесты затронутых контекстов.
- Пересобрать ассеты, если трогал JS/Twig.
- НЕ делать commit без явной команды пользователя.

<!-- rtk-instructions v2 -->
# RTK (Rust Token Killer) - Token-Optimized Commands

## Golden Rule

**Always prefix commands with `rtk`**. If RTK has a dedicated filter, it uses it. If not, it passes through unchanged. This means RTK is always safe to use.

**Important**: Even in command chains with `&&`, use `rtk`:
```bash
# ❌ Wrong
git add . && git commit -m "msg" && git push

# ✅ Correct
rtk git add . && rtk git commit -m "msg" && rtk git push
```

## RTK Commands by Workflow

### Build & Compile (80-90% savings)
```bash
rtk cargo build         # Cargo build output
rtk cargo check         # Cargo check output
rtk cargo clippy        # Clippy warnings grouped by file (80%)
rtk tsc                 # TypeScript errors grouped by file/code (83%)
rtk lint                # ESLint/Biome violations grouped (84%)
rtk prettier --check    # Files needing format only (70%)
rtk next build          # Next.js build with route metrics (87%)
```

### Test (60-99% savings)
```bash
rtk cargo test          # Cargo test failures only (90%)
rtk go test             # Go test failures only (90%)
rtk jest                # Jest failures only (99.5%)
rtk vitest              # Vitest failures only (99.5%)
rtk playwright test     # Playwright failures only (94%)
rtk pytest              # Python test failures only (90%)
rtk rake test           # Ruby test failures only (90%)
rtk rspec               # RSpec test failures only (60%)
rtk test <cmd>          # Generic test wrapper - failures only
```

### Git (59-80% savings)
```bash
rtk git status          # Compact status
rtk git log             # Compact log (works with all git flags)
rtk git diff            # Compact diff (80%)
rtk git show            # Compact show (80%)
rtk git add             # Ultra-compact confirmations (59%)
rtk git commit          # Ultra-compact confirmations (59%)
rtk git push            # Ultra-compact confirmations
rtk git pull            # Ultra-compact confirmations
rtk git branch          # Compact branch list
rtk git fetch           # Compact fetch
rtk git stash           # Compact stash
rtk git worktree        # Compact worktree
```

Note: Git passthrough works for ALL subcommands, even those not explicitly listed.

### GitHub (26-87% savings)
```bash
rtk gh pr view <num>    # Compact PR view (87%)
rtk gh pr checks        # Compact PR checks (79%)
rtk gh run list         # Compact workflow runs (82%)
rtk gh issue list       # Compact issue list (80%)
rtk gh api              # Compact API responses (26%)
```

### JavaScript/TypeScript Tooling (70-90% savings)
```bash
rtk pnpm list           # Compact dependency tree (70%)
rtk pnpm outdated       # Compact outdated packages (80%)
rtk pnpm install        # Compact install output (90%)
rtk npm run <script>    # Compact npm script output
rtk npx <cmd>           # Compact npx command output
rtk prisma              # Prisma without ASCII art (88%)
```

### Files & Search (60-75% savings)
```bash
rtk ls <path>           # Tree format, compact (65%)
rtk read <file>         # Code reading with filtering (60%)
rtk grep <pattern>      # Search grouped by file (75%). Format flags (-c, -l, -L, -o, -Z) run raw.
rtk find <pattern>      # Find grouped by directory (70%)
```

### Analysis & Debug (70-90% savings)
```bash
rtk err <cmd>           # Filter errors only from any command
rtk log <file>          # Deduplicated logs with counts
rtk json <file>         # JSON structure without values
rtk deps                # Dependency overview
rtk env                 # Environment variables compact
rtk summary <cmd>       # Smart summary of command output
rtk diff                # Ultra-compact diffs
```

### Infrastructure (85% savings)
```bash
rtk docker ps           # Compact container list
rtk docker images       # Compact image list
rtk docker logs <c>     # Deduplicated logs
rtk kubectl get         # Compact resource list
rtk kubectl logs        # Deduplicated pod logs
```

### Network (65-70% savings)
```bash
rtk curl <url>          # Compact HTTP responses (70%)
rtk wget <url>          # Compact download output (65%)
```

### Meta Commands
```bash
rtk gain                # View token savings statistics
rtk gain --history      # View command history with savings
rtk discover            # Analyze Claude Code sessions for missed RTK usage
rtk proxy <cmd>         # Run command without filtering (for debugging)
rtk init                # Add RTK instructions to CLAUDE.md
rtk init --global       # Add RTK to ~/.claude/CLAUDE.md
```

## Token Savings Overview

| Category | Commands | Typical Savings |
|----------|----------|-----------------|
| Tests | vitest, playwright, cargo test | 90-99% |
| Build | next, tsc, lint, prettier | 70-87% |
| Git | status, log, diff, add, commit | 59-80% |
| GitHub | gh pr, gh run, gh issue | 26-87% |
| Package Managers | pnpm, npm, npx | 70-90% |
| Files | ls, read, grep, find | 60-75% |
| Infrastructure | docker, kubectl | 85% |
| Network | curl, wget | 65-70% |

Overall average: **60-90% token reduction** on common development operations.
<!-- /rtk-instructions -->
