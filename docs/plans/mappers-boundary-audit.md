# Аудит границ мэпперов: «мэппер только мапит»

По указанию заказчика: границы размылись, пройтись по ВСЕМ мэпперам. Отдельный рефактор
(тянет существующие фичи). Аудит проведён 2026-09-12 (2 приоритетных прочитаны вручную,
8 остальных — Explore-агентом).

## Правило (CLAUDE.md)
Mapper — **pure shape mapping**: форма/JSON ↔ DTO/Command, только структура. Никаких бизнес-
фильтров, никаких `throw` про правила домена, никаких вычислений/решений/конверсий единиц.
Форм-специфичную нормализацию («пустая ячейка = нет значения») допускаем отдельным именованным
методом, но решения и инварианты — не здесь (интерпретация → хендлер, инварианты → домен,
структурная валидация → Assert-коллекция с человеческим message).

## Итог аудита
| Мэппер | Вердикт | Что именно |
|---|---|---|
| `Coatings/CoatingMapper` | **ДРЕЙФ** | арифметика длительности `parseDurationInput`/`decomposeDurationForForm`; 4 `throw` в `buildExposureFromInput`; решения «→null» (exposure all-empty, max-tree empty стр.108, time 0) |
| `Certificates/DocumentMapper` | **ДРЕЙФ** | 9 структурных `throw` (UUID/enum/дата/файл); нет Assert-коллекции; сам собирает `Reference`/`Uuid`; валидация файла size/mime |
| `Coatings/CoatingListRequestMapper` | **ДРЕЙФ** | конверсия единиц: `MINUTES_PER_HOUR=60`/`MINUTES_PER_DAY=1440` + множители (стр. 24-26, 58-59) |
| `Coatings/CoatingSystemListRequestMapper` | **ДРЕЙФ** | конверсия единиц (стр. 27, 64) + бизнес-правило compliance-каскада (стр. 51-52) |
| `Coatings/CoatingSystemMapper` | чисто | нит: `Substrate::from`/`EnvironmentType::from` → `tryFrom`+Assert |
| `Coatings/SurfaceTreatmentMapper` | чисто | нит: `Substrate::from` → `tryFrom`+Assert |
| `Coatings/CoatingListRequestMapper`… | (см. выше) | |
| `ChemicalResistance/AssessmentMapper` | чисто | — |
| `Documents/DocumentMapper` | чисто* | *ES `_source` → доменный агрегат `Document`+VO (реконституция read-model, транзитивные инвариант-throw'ы) — другая категория, не форм-мэппер |
| `Certificates/DocumentListRequestMapper` | чисто | — |
| `Proposals/GeneralProposalInfoMapper` | чисто | — |

## Что делать (по дрейфу)

### Сквозное: VO `Duration` (Shared/Domain)
Конверсия часы/дни↔минуты дублируется в CoatingMapper и обоих list-мэпперах. Завести
`Duration` VO, владеющий minutes↔{days,hours,minutes} и множителями. Все три мэппера
делегируют в него. Убирает дублирование и конверсию из инфраструктуры разом.

### CoatingMapper
- Длительность → через `Duration` VO (см. выше).
- `buildExposureFromInput` throw'ы → Assert-констрейнты «целое число» с человеческим message
  (по образцу остальных полей `getValidationCollectionCoating`); мэппер только shape.
- Решения «→null» (exposure all-empty, max-tree empty, time 0) → в хендлер (прецедент —
  mixingRatio: мэппер всегда отдаёт DTO, решение о null в хендлере).

### Certificates/DocumentMapper
- Завести `getValidationCollection...()` (Assert) для формы документа: UUID (`Assert\Uuid`),
  enum вида/типа (`Assert\Choice`), дата (`Assert\NotBlank`+`Assert\Date`), файл
  (`Assert\File` mimeTypes=pdf/maxSize=8M — встроенный, не свой) — с человеческими message.
- После валидации мэппер строит Command/`Reference`/`Uuid` без единого `throw`.

### CoatingSystemListRequestMapper
- Конверсия единиц → `Duration` VO.
- Compliance-каскад (category/durability только при standard) → в `CoatingSystemsFilter`/домен.

### Мелочь
- `::from()` → `tryFrom()` + `Assert\Choice` в CoatingSystemMapper/SurfaceTreatmentMapper.

## Разбивка на чанки (многоэтапно — по одному плану/ветке на чанк)
1. **Duration VO** + починка конверсии в 3 местах (CoatingMapper duration, 2 list-мэппера).
2. **CoatingMapper**: exposure throw→Assert + решения «→null»→хендлер.
3. **Certificates/DocumentMapper**: Assert-коллекция (+`Assert\File`) + снять throw'ы + решения→хендлер.
4. **CoatingSystemListRequestMapper**: compliance-каскад → домен (+ мелочь `::from`→`tryFrom`).

## Верификация (на каждый чанк)
`./run check` + функц.-тесты затронутых форм/списков (создание/обновление/ре-рендер ошибок,
фасеты фильтров). Round-trip юнит-тесты мэпперов (`build → decompose → build`).

## Статус
Аудит готов (2026-09-12). Реализация — по чанкам, каждый отдельной веткой, по подтверждению.
