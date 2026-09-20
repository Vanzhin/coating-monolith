# Аудит: гранулярные + человекочитаемые изменения VO

> **Для исполнителя:** реализовывать через subagent-driven-development, по задачам,
> с ревью между ними. Ветка `feat/audit-log-view` (продолжение). Юнит — на хосте,
> функциональные — в контейнере (`manager_php-fpm`, override DATABASE_URL). Гейты
> `./run check` (style/phpstan) закладывать в каждую задачу — наш код их иначе валит.

**Цель:** в ленте аудита изменения ВНУТРИ сложных VO фиксируются точечно
(«что именно поменялось»), а не «весь VO как изменён», и показываются
человекочитаемо.

**Связь:** продолжение фичи аудита (`docs/plans/audit-log-1-capture.md`,
`audit-log-2-view.md`). Стиль карточки (Вариант A) закоммичен — `815765b`.

---

## Диагноз (выверено по коду)

`JsonDiff` (Shared/Domain/Audit):
- **Карты (объекты)** — `diffMap` уже разбирает вглубь до скаляра. DFT-правка `min`
  → `set(path="dftRange.min", 50, 60)`. Точечно, ок.
- **Списки точек** (`diffList`) — сопоставляет элементы по ПОЛНОМУ равенству
  (`===` на массивах), без ключа идентичности. Правка одной точки серии
  (5 °C: 960→900) = старый элемент целиком `remove` + новый целиком `add`, оба с
  путём `<field>` без индекса. Отсюда «поменялось всё».
- Слушатель зовёт `diff($old,$new,$field)` (path = имя поля). Транслятор
  `AuditLogTransformer::label()` берёт head-поле из карты подписей, tail пути
  вешает через `·`.

Затронутые поля-серии (список точек `[{temperature_at, time_in_minutes, is_calculated}]`):
`dryToTouch`, `fullCure`, и внутри деревьев перекрытия `minRecoatingInterval`/
`maxRecoatingInterval` (`{default:[серия], children:{ключ:узел}}`).

Семантика точки (VO `TimeAtTemperature`): `time_in_minutes` `>0` длительность,
`0` «без ограничения», `null` «нет данных»; `is_calculated=true` — интерполяция.

---

## Слой 1 — гранулярность: keyed-diff списков (Domain, generic)

**Файл:** `app/src/Shared/Domain/Audit/JsonDiff.php` — доработать `diffList`.

**Правило.** Если обе стороны — непустые списки МАП, и есть **ключ идентичности** —
сопоставлять элементы по нему и рекурсивно диффать; иначе fallback к текущему
поведению (deep-equality).

**Как ищем ключ идентичности** (обобщённо, без хардкода домена):
- кандидат — ключ, который присутствует в КАЖДОМ элементе old и new, его значение —
  ненулевой скаляр во всех, и значения УНИКАЛЬНЫ внутри old и внутри new;
- берём ПЕРВЫЙ такой ключ в порядке ключей первого элемента old (детерминированно).
  Для точек это `temperature_at` (идёт первым в сериализации).
- нет кандидата → fallback (текущее поведение). Худший случай не хуже нынешнего.

**Алгоритм keyed-ветки:**
```
oldByKey = { элемент[k] => элемент }  для old
newByKey = { элемент[k] => элемент }  для new
для каждого kv в union(ключи oldByKey, ключи newByKey), в порядке old потом new-only:
  в обоих → recurse diff(oldByKey[kv], newByKey[kv], path.'.'.kv)   // → set на изменившемся под-поле
  только new → add(path.'.'.kv, newByKey[kv])                       // новая точка
  только old → remove(path.'.'.kv, oldByKey[kv])                    // убрана точка
```
Идентичный под-ключ (`temperature_at`) при рекурсии не меняется → в дифф не попадает.
Композиция работает и для дерева: `minRecoatingInterval` — карта `{default, children}`,
`default` — список → keyed; `children` — карта → drills; итог напр.
`minRecoatingInterval.default.20.time_in_minutes: X→Y` или
`minRecoatingInterval.children.<key>.default.20.time_in_minutes: X→Y`.

**Осознанно пересматривает** решение 2026-09-17 «без авто-ключа в списках» — теперь
авто-ключ включён для списков-мап с найденным ключом идентичности.

**Тесты** (`tests/Unit/Shared/Domain/Audit/JsonDiffTest.php`, дополнить):
- правка одной точки: `[{t:5,m:960},{t:10,m:360}]`→`[{t:5,m:900},{t:10,m:360}]`
  = ровно один `set(path="f.5.time_in_minutes", 960, 900)`.
- добавлена точка: `add(path="f.15", {...})`; убрана: `remove(path="f.10", {...})`.
- список без общего уникального скаляра (напр. list of strings) → fallback (как раньше).
- дерево: правка default-точки → `set("mri.default.20.time_in_minutes", ...)`.

---

## Слой 2 — читаемость (презентация, аудит-safe, НЕ через доменный fromArray)

**Почему не через VO::fromArray:** прогоняет инварианты, может кинуть на исторических/
частичных снимках. Аудит обязан рендерить всегда. Читаем сырой массив структурно.

**Где:** `AuditLogTransformer` при сборке `FieldChangeView` вызывает
`AuditChangePresenter`, который по (path, old, new, карта подписей) отдаёт готовые
**humanLabel / humanOld / humanNew** (строки). Twig печатает строки.

**Файлы** под `app/src/Shared/Application/Audit/Present/`:
- `AuditChangePresenter.php` — оркестратор: строит label по path, значения гонит
  через `ValueHumanizer`.
- `ValueHumanizer.php` — цепочка форматтеров значения; при исключении → JSON-fallback,
  НИКОГДА не бросает.
- `DurationHumanizer.php` — `int|null $minutes → string`.
- `Formatter/` — `ScalarFormatter`, `DftRangeFormatter`, `DurationSeriesFormatter`,
  `RecoatingTreeFormatter`, `ThermalLimitsFormatter`, `MixingRatioFormatter`,
  `JsonFallbackFormatter`.

Правки: `AuditLogTransformer` (использовать presenter), `_audit_entry.html.twig`
(печатать `change.humanOld`/`change.humanNew`, убрать `import`), удалить
`_audit_value.html.twig`. `FieldChangeView` — добавить поля humanLabel/humanOld/humanNew
(или заменить label/old/new на готовые строки).

### Подпись (label) по форме пути

| Путь | Подпись |
|---|---|
| `<field>` (скаляр) | подпись поля |
| `dftRange.min\|max\|tds_dft\|type` | «Толщина плёнки (DFT) · мин./макс./целевая/единица» |
| `dryHeatExposure.*` / `immersionExposure.*` | подпись поля + рус. под-ключ (непр. мин/непр. макс/пик/длит. пика) |
| `<seriesField>.<temp>.time_in_minutes` | «<подпись поля>, <temp> °C» |
| `<seriesField>.<temp>` (add/remove точки) | «<подпись поля>, <temp> °C» |
| `<treeField>.default.<temp>[.time_in_minutes]` | «<подпись поля>, <temp> °C» (сегмент `default` опустить) |
| `<treeField>.children.<k1>[.children.<k2>…].default.<temp>[.time_in_minutes]` | «<подпись поля> над слоем «<k1 / k2 / …>», <temp> °C» — дерево ПРОИЗВОЛЬНОЙ глубины (домен: root→среда→основа, ~20% разветвлены), children-ветку обходить рекурсивно, а не по фиксированным индексам |
| прочее с tail | подпись поля + ` · ` + tail (как сейчас) |

Сегмент `is_calculated` в tail → изменение флага скрывать (не показывать строку).

### Значения (ValueHumanizer, структурно по форме)

| Форма значения | Рендер |
|---|---|
| bool | Да / Нет |
| null | — |
| строка/число | как есть |
| минуты (значение под ключом `time_in_minutes`, в keyed-изменении) | DurationHumanizer |
| `{min,max,tds_dft,type}` (DFT целиком, на создании) | «50–100 мкм (целевая 75)» |
| `[{temperature_at,time_in_minutes,is_calculated},…]` (серия целиком) | «5 °C — 16 ч; 10 °C — 6 ч; …» |
| `{default,children}` (дерево целиком) | серия default + для детей «над слоем «<key>»: <серия>» |
| `{continuous_min,continuous_max,peak_max,peak_duration_minutes}` | «непрерывно −30…+120 °C; пик +140 °C до 60 мин» (пустые части опустить) |
| `{volume,mass}` (пропорция целиком) | «по объёму 4:1; по массе 4:1» (пустые опустить) |
| неизвестная форма | pretty JSON `JSON_UNESCAPED_UNICODE\|JSON_PRETTY_PRINT` (fallback) |

**DurationHumanizer** (D1): `сут/ч/мин`, максимум 2 старшие ненулевые единицы, точно:
`0→«без ограничения»`, `null→«нет данных»`, `960→«16 ч»`, `505→«8 ч 25 мин»`,
`30240→«21 сут»`, `1440→«1 сут»`, `540→«9 ч»`.

**Серия целиком** (D2): только `is_calculated=false`; `0→«без огр.»`; `null` — скрывать.

**Итоговый вид ленты:**
```
Высыхание на отлип, 5 °C: 16 ч → 15 ч        (правка точки)
Мин. интервал перекрытия, 20 °C: 9 ч → 8 ч
Толщина плёнки (DFT): 50–100 мкм (целевая 75) (создание, целиком)
```

---

## Решения (дефолты приняты; поправим по факту)

- **D1** длительность: `сут/ч/мин`, 2 ед., точно. (Принято.)
- **D2** точки серий: только введённые; `0→«без огр.»`; `null` скрывать. (Принято.)
- **D3** enum-поля (`Основа`=PUR, `Глянец`=gloss, `Модель`=LINEAR): **v1 — сырой код**
  (ScalarFormatter отдаёт как есть). Рус. подписи enum — отдельным follow-up.
- **D4** дети дерева: `default` + «над слоем «<key>»: серия». (Принято.)

## Задачи (bite-sized, TDD)

- **T1. Keyed-diff в JsonDiff** (Слой 1) + юнит-тесты (правка/добавление/удаление точки,
  fallback, дерево). Самодостаточно, ничего из Слоя 2 не трогает.
- **T2. DurationHumanizer** + тест (граничные из D1).
- **T3. ValueHumanizer + ScalarFormatter + JsonFallbackFormatter** цепочка + тест
  (bool/null/строка/число + неизвестная форма→pretty JSON с кириллицей без `\uXXXX`).
- **T4. DftRangeFormatter, ThermalLimitsFormatter, MixingRatioFormatter** + тесты.
- **T5. DurationSeriesFormatter + RecoatingTreeFormatter** (используют DurationHumanizer;
  D2/D4) + тесты.
- **T6. AuditChangePresenter** (label по форме пути из таблицы + значения через
  ValueHumanizer; скрытие `is_calculated`) + тест на каждую строку таблицы путей.
- **T7. Интеграция в AuditLogTransformer + FieldChangeView** (humanLabel/humanOld/humanNew).
  Функциональный тест: правка точки покрытия → одна человекочитаемая строка.
- **T8. Twig:** `_audit_entry.html.twig` печатает строки, удалить `_audit_value.html.twig`,
  `yarn dev`, `lint:twig`; проверить историю+журнал в браузере.
- **T9. Гейты:** `./run check` по нашим файлам, финальный ревью Слоёв 1+2.

## Порядок

1. Слой 1 (T1) — прогнать и показать пользователю (гранулярность видна уже в сыром виде).
2. Слой 2 (T2–T8).
3. Гейты (T9) → финальный ревью всей ветки `feat/audit-log-view` (readability + фильтр +
   модалки + стиль + гранулярность + рендер) → мерж в main **обычным merge-коммитом**
   (ff уже невозможен: main ушёл вперёд после PR #71 движка документов). Не пушить.

## Отдельно (НЕ эта ветка)

- **#17 Глобальный перехват 500** → flash + бэк-лог. Отдельная ветка от main после мержа.
- **Follow-up:** рус. подписи enum-значений (D3) — если понадобится.
