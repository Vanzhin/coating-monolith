# Правила работы с кодом в этом проекте

Прочти ДО того, как редактировать код. Эти правила — итог реальных разборов багов, а не теории. Нарушать их можно, но только с явным согласием пользователя.

## Главные принципы

**1. Самая важная логика — в доменных объектах.** Бизнес-правила, инварианты, расчёты, физика предметной области — всё это живёт в `{Context}/Domain/` (агрегаты, VO, доменные сервисы). Не в хендлерах, не в мапперах, не в контроллерах, не в Twig, не в JS. Если правило размазано по слоям — это бага архитектуры, а не «удобство». Application/Infrastructure только оркеструют и адаптируют, домен принимает решения.

**2. Бэк — единственный источник истины для валидации.** Любая бизнес-проверка живёт на сервере и при нарушении возвращает либо OK, либо ошибку, которую фронт рендерит. Дубликаты на фронте допустимы только ради UX (мгновенная подсказка), но при отсутствии JS форма обязана работать корректно через серверную валидацию. Фронт никогда не решает «можно ли сохранить» — только показывает то, что вернул бэк.

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
