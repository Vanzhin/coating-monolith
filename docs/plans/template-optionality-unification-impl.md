# Единый механизм опциональности/повтора docx-движка — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development (рекомендуется) или superpowers:executing-plans. Шаги — чекбоксы `- [ ]`.

**Goal:** Свести опциональность/повтор docx-движка к одному правилу — `?`-суффикс = «необязательно», парные маркеры = регион, повтор = точечные подполя — предсказуемо и без коллизий.

**Architecture:** Правим парсер/валидатор/рендерер `DocxTemplateRenderer` (+ `DocxMacroProcessor`) так, чтобы маркеры региона несли `?`-суффикс (опциональность), строгий пустой регион шёл в `missing`, а инлайн-регион (маркеры в одном абзаце) вырезался регэкспом, блочный — через PhpWord deleteBlock/cloneBlock. Удаляем парсинг префикс-сегмента `{{?x}}`.

**Tech Stack:** PHP 8.5, Symfony 8, PhpWord (TemplateProcessor), PHPUnit 11. Тесты — в контейнере через `./run check`.

**Spec:** `docs/plans/template-optionality-unification.md`

## Global Constraints

- `?` — только СУФФИКС, только «необязательно». `{{x}}` строгий, `{{x?}}` опц. Регион = парные `{{x}}…{{/x}}` / `{{x?}}…{{/x?}}`. Повтор = наличие `{{x.sub}}` внутри.
- Логическое имя = имя без хвостового `?`. `?` на open и close региона совпадают; рассинхрон → `AppException` с человекочитаемым текстом.
- Строгий (без `?`) пустой плейсхолдер/регион → `ValidationResult.missing` (файл не собрать). Опц. (`?`) пустой → `skipped` (тихо выпадает). Повтор строкой таблицы (голые `{{g.sub}}` в `<w:tr>` без обёрточных маркеров) — опционален по природе (пусто → строка удаляется), НЕ трогаем.
- Удалить парсинг префикс-сегмента `{{?x}}`/`{{/?x}}` (метод `resolveTopLevelSegments` и ветки parse для `?name`/`/?name`). `{{opt_x}}` спец-кода не имеет (обычный блок) — не трогаем, просто не используем opt_-имена.
- НЕ редактировать реальные .docx (это делает пользователь по правилам спеки). Правку .docx-шаблонов в план НЕ включаем.
- Коммиты — ТОЛЬКО по явному апруву пользователя ([[feedback_no_commits]]); шаги «Commit» в задачах = точка апрува, а не автокоммит.
- Существующие повтор-тесты (`DocxTemplateRendererRepeatTest`) и Reports functional — держать зелёными.
- Гейты: `./run check` (style/phpstan/unit/functional) в контейнере; если functional падает на «relation … does not exist» — `docker compose -f docker-compose.test.yml run --rm test_php-cli sh -c 'bin/console doctrine:schema:drop --full-database --force -n && bin/console doctrine:migrations:migrate -n'`.

## Файлы

- Modify: `app/src/Shared/Infrastructure/Service/DocxTemplateRenderer.php` — `parse()` (маркеры региона с `?`, детект инлайн/блок, удалить сегмент-ветки), `validate()` (строгий регион пуст → missing), `render()` (опц. регион), `blockStates()` (учёт optional-флага блока).
- Modify: `app/src/Shared/Infrastructure/Service/DocxMacroProcessor.php` — заменить `resolveTopLevelSegments`/`resolveRowSegments` (сегмент `{{?x}}`) на вырез инлайн-региона `{{x?}}…{{/x?}}`; `normalizeMacros` — убедиться, что `?` на маркерах региона не рвётся.
- Test: `app/tests/Unit/Shared/Infrastructure/Service/DocxTemplateRendererOptionalityTest.php` (новый), правки в `DocxTemplateRendererRepeatTest.php`, `DocxTemplateRendererSegmentTest.php` (переписать под новый синтаксис).
- Modify: `docs/plans/report-template-placeholders.md` — описать 4 формы + `?`-правило + «заголовок внутрь маркеров».
- (Опц.) Create: `app/bin/…` НЕ надо — миграцию .docx делает пользователь; при желании отдельный throwaway-скрипт в scratchpad, в репо не кладём.

---

### Task 1: Опциональные маркеры региона в parse() (`{{x?}}…{{/x?}}`)

**Files:**
- Modify: `app/src/Shared/Infrastructure/Service/DocxTemplateRenderer.php` (метод `parse()`, ~334–447)
- Test: `app/tests/Unit/Shared/Infrastructure/Service/DocxTemplateRendererOptionalityTest.php`

**Interfaces:**
- Produces: `parse()` возвращает `blocks` как `array<string,array{optional:bool, values:list<string>}>` (было `array<string,list<string>>`); `values[]` элемент неизменен `{logical,token,optional,block}`; `repeats`/`segments` — `segments` УДАЛЯЕТСЯ из результата в Task 5.

- [ ] **Step 1: Failing test — опц. блок распознаётся, `?` снят с логического имени**

```php
public function test_optional_block_marker_parsed_as_optional_region(): void
{
    // шаблон: {{note?}} … {{/note?}} вокруг {{note}}; парсер должен увидеть блок 'note' optional=true
    $tpl = $this->docxWithParagraphs(['{{note?}}', 'Замечание: {{note}}', '{{/note?}}']);
    $parsed = $this->invokeParse($tpl); // хелпер: рефлексией зовёт private parse() на загруженном процессоре
    self::assertArrayHasKey('note', $parsed['blocks']);
    self::assertTrue($parsed['blocks']['note']['optional']);
    // {{/note?}} НЕ создаёт фантомный блок 'note?'
    self::assertArrayNotHasKey('note?', $parsed['blocks']);
}
```
(Хелперы `docxWithParagraphs` — строит PhpWord с абзацами; `invokeParse` — рефлексия `load()`+`parse()`. Вынести в base-тест или трейт.)

- [ ] **Step 2: Run — FAIL** (`./run check unit` или прямой `phpunit --filter test_optional_block_marker...`). Ожидание: `note?` в blocks / нет optional-ключа.

- [ ] **Step 3: Реализация в `parse()`**
  - В сборке `$closeSet`: закрытие `/name` ИЛИ `/name?` → `$closeSet[rtrim($n,'?')] = str_ends_with($n,'?')` (значение = optional-флаг). `/?name` (префикс-сегмент) пока оставить (уберём в Task 5).
  - При открытии блока: `$content` совпадает (после снятия `?`) с ключом `$closeSet` → это open региона; `$logical = rtrim($content,'?')`; завести `$blocks[$logical] ??= ['optional'=>$closeSet[$logical], 'values'=>[]]`; в стек класть `$logical`.
  - Membership: `$blocks[$enclosing]['values'][] = $logical`.
  - Строгий/опц.: `optional` элемента `values[]` = `$optionalMark || (block!==null && blocks[block].optional) || segmentStack`. ВНИМАНИЕ: значение внутри СТРОГОГО блока НЕ опционально (строгий блок с пустым обязательным полем → missing). Внутри опц. блока — опционально.

- [ ] **Step 4: Run — PASS.**

- [ ] **Step 5: Commit (апрув)** — `parse: опциональные маркеры региона {{x?}}…{{/x?}}`.

---

### Task 2: `blockStates()` + `validate()` — строгий пустой регион → missing

**Files:**
- Modify: `DocxTemplateRenderer.php` (`blockStates()` ~449, `validate()` ~64)
- Test: тот же Optionality-тест.

**Interfaces:**
- Consumes: `parse()['blocks'][name]['optional']` (Task 1).
- Produces: `blockStates()` даёт состояния `keep|delete|missing|partial`; `validate()` кладёт строгий пустой блок в `missing`.

- [ ] **Step 1: Failing tests**

```php
public function test_required_empty_region_is_missing(): void
{
    $tpl = $this->docxWithParagraphs(['{{note}}', 'Замечание: {{note}}', '{{/note}}']); // строгий (без ?)
    $res = (new DocxTemplateRenderer())->validate($tpl, new RenderData([])); // нет 'note'
    self::assertContains('note', $res->missing);
}
public function test_optional_empty_region_is_skipped_not_missing(): void
{
    $tpl = $this->docxWithParagraphs(['{{note?}}', 'Замечание: {{note}}', '{{/note?}}']);
    $res = (new DocxTemplateRenderer())->validate($tpl, new RenderData([]));
    self::assertNotContains('note', $res->missing);
    self::assertContains('note', $res->skipped);
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Реализация**
  - `blockStates()`: для блока с данными → `keep`; без данных → если `optional` → `delete`, иначе `missing`. (Сейчас без данных всегда delete.) «Есть данные» = как сейчас (любое присутствующее значение/строка группы внутри).
  - `validate()`: в цикле по `states` — `missing`-состояние блока → добавить имя блока в `$missing`; `delete` → его значения в `skipped` (как сейчас). Для повтора: строгий блок-повтор с пустым RepeatValue (нет строк) → `missing`; опц. → `skipped`. (Проверка «нет строк» = `!$data->has(group)` или пустой `RepeatValue.rows`.)

- [ ] **Step 4: Run — PASS.**

- [ ] **Step 5: Commit (апрув)** — `validate: строгий пустой регион/повтор → missing, опц. → skipped`.

---

### Task 3: render() опц. БЛОЧНОГО региона — регресс на баг обрыва

**Files:**
- Modify: `DocxTemplateRenderer.php` (`render()` ~143–211)
- Test: Optionality-тест.

**Interfaces:**
- Consumes: `blockStates()` (Task 2).

- [ ] **Step 1: Failing test — контент ПОСЛЕ пустого опц-региона цел (это регресс на баг обрыва)**

```php
public function test_empty_optional_block_removed_content_after_survives(): void
{
    $tpl = $this->docxWithParagraphs([
        'Процесс:', '{{proc?}}', '1. {{proc}}', '{{/proc?}}',
        'Рекомендации: {{rec?}}',
    ]);
    $text = $this->render($tpl, new RenderData(['rec' => new TextValue('носить каску')])); // proc пуст
    self::assertStringNotContainsString('{{', $text);
    self::assertStringContainsString('Рекомендации: носить каску', $text); // контент после НЕ обрублен
    self::assertStringNotContainsString('1.', $text); // тело региона ушло
}
```

- [ ] **Step 2: Run — FAIL** (или уже проходит для блока — тогда тест закрепляет регресс).

- [ ] **Step 3: Реализация** — существующая ветка `render()` для блоков (`'keep' → cloneBlock(name,1)`, иначе `deleteBlock(name)`) уже делает это корректно для БЛОЧНОГО региона; убедиться, что после Task 1/2 блок 'proc' (optional, пусто) идёт по `delete → deleteBlock`. Правок может не быть — тест закрепляет поведение (безопасно, deleteBlock параграфный).

- [ ] **Step 4: Run — PASS.**

- [ ] **Step 5: Commit (апрув)** — `test: регресс — пустой опц-блок не обрубает документ`.

---

### Task 4: Инлайн опц-регион (маркеры в одном абзаце) — вырез вместо префикс-сегмента

**Files:**
- Modify: `DocxMacroProcessor.php` (`resolveTopLevelSegments`→новый `resolveInlineOptional`), `DocxTemplateRenderer.php` (детект инлайн vs блок в parse/render)
- Test: `DocxTemplateRendererSegmentTest.php` (переписать под `{{x?}}…{{/x?}}`)

**Interfaces:**
- Produces: инлайн-регион = парные маркеры В ОДНОМ `<w:p>`; пусто → вырезается со всем литералом между маркерами; есть данные → маркеры снимаются, `{{x}}` заполняется.

- [ ] **Step 1: Failing test**

```php
public function test_inline_optional_region_cut_when_empty(): void
{
    // {{sensor?}}Датчик: {{sensor}}{{/sensor?}} в ОДНОМ абзаце
    $tpl = $this->docxWithInline('{{sensor?}}Датчик: {{sensor}}{{/sensor?}}');
    self::assertStringNotContainsString('Датчик', $this->render($tpl, new RenderData([])));       // пусто → всё ушло
    self::assertStringContainsString('Датчик: A1', $this->render($tpl, new RenderData(['sensor'=>new TextValue('A1')]))); // есть → заполнен
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Реализация**
  - В `parse()` для региона определить `inline`: open и close токены в одном абзаце. Практично — проверить в сыром `tempDocumentMainPart`, что между `{{name?}}` и `{{/name?}}` НЕТ границы абзаца (`</w:p>`). Вынести в `DocxMacroProcessor::isInlineRegion(string $logical): bool`.
  - `resolveInlineOptional(array $presence)` (на базе старого `resolveTopLevelSegments`): regex `{{name?}}(.*?){{/name?}}` (`/su`) → present? оставить `$1` (со снятыми маркерами, `{{name}}` заполнится в fill) : удалить всё. Работает по `?`-суффикс-синтаксису вместо `?`-префикса.
  - `render()`: инлайн-регионы обрабатывать как top-level presence (не блок/не повтор): has(name) → keep+fill, иначе cut.
  - Инлайн-регион в parse НЕ регистрировать как block (deleteBlock его не тронет — маркеры не абзацами); держать отдельным списком `inlineRegions` в parse-результате.

- [ ] **Step 4: Run — PASS** (+ прежний segment-тест переписан на новый синтаксис и зелёный).

- [ ] **Step 5: Commit (апрув)** — `движок: инлайн опц-регион {{x?}}…{{/x?}}` (замена префикс-сегмента).

---

### Task 5: Удалить парсинг префикс-сегмента `{{?x}}`/`{{/?x}}`

**Files:**
- Modify: `DocxTemplateRenderer.php` (`parse()` — убрать ветки `?name`/`/?name`, `segments` из результата, `render()` — убрать вызовы top-level/row сегментов), `DocxMacroProcessor.php` (удалить `resolveTopLevelSegments`, `resolveRowSegments` если не переиспользованы Task 4).

**Interfaces:**
- Consumes: инлайн-регион (Task 4) полностью заменяет сегмент.

- [ ] **Step 1: Failing/guard test — `{{?x}}` больше не спец-синтаксис**

```php
public function test_prefix_segment_syntax_is_no_longer_special(): void
{
    // Легаси {{?x}} теперь трактуется как обычный (странный) плейсхолдер, а НЕ сегмент — движок не падает,
    // а сообщает leftover/missing понятно. Фиксируем, что ветки сегмента удалены.
    $parsed = $this->invokeParse($this->docxWithInline('{{?legacy}}x{{/?legacy}}'));
    self::assertArrayNotHasKey('segments', $parsed); // ключ убран
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Реализация** — вырезать из `parse()` обработку `str_starts_with($content,'/?')`, `preg_match('/^\?.../')`, `$segmentStack`, `$segmentNames`, ключ `segments` в возврате; из `render()` — блок `resolveTopLevelSegments`/`segmentPresence` и вызов `resolveRowSegments` в repeatRow/repeatBlock (заменить пусто, т.к. инлайн-подполя строк повтора — отдельная редкая ниша; если нужны — отдельная задача). Удалить неиспользуемые методы в `DocxMacroProcessor`.

- [ ] **Step 4: Run — PASS + существующие повтор-тесты зелёные** (`./run check unit`).

- [ ] **Step 5: Commit (апрув)** — `движок: удалён префикс-сегмент {{?x}}, единый {{x?}} остаётся`.

---

### Task 6: Рассинхрон `?` на маркерах региона → человекочитаемая ошибка

**Files:**
- Modify: `DocxTemplateRenderer.php` (`parse()` или `validate()`)
- Test: Optionality-тест.

- [ ] **Step 1: Failing test**

```php
public function test_mismatched_optional_markers_throws_readable_error(): void
{
    $tpl = $this->docxWithParagraphs(['{{note?}}', '{{note}}', '{{/note}}']); // open с ?, close без ?
    $this->expectException(AppException::class);
    $this->expectExceptionMessageMatches('/note.*маркер|маркер.*note/iu');
    (new DocxTemplateRenderer())->validate($tpl, new RenderData([]));
}
```

- [ ] **Step 2: Run — FAIL.**

- [ ] **Step 3: Реализация** — в `parse()` при сборке блоков: если для логического имени встречены open и close с РАЗНЫМ `?` (open optional ≠ close optional) → `throw new AppException(sprintf('Шаблон: маркеры региона «%s» рассинхронизированы по «?» — {{%1$s}}…{{/%1$s}} или {{%1$s?}}…{{/%1$s?}}.', $logical))`. Хранить optional-флаг open и сверять с close.

- [ ] **Step 4: Run — PASS.**

- [ ] **Step 5: Commit (апрув)** — `движок: рассинхрон ? на маркерах региона → понятная ошибка`.

---

### Task 7: Контракт-док + полный gate + Reports functional

**Files:**
- Modify: `docs/plans/report-template-placeholders.md`
- (проверка) весь движок + Reports.

- [ ] **Step 1: Обновить `report-template-placeholders.md`** — раздел «Формы плейсхолдеров»: 4 формы из спеки, правило `?`=необязательно, «удаляется что между маркерами → заголовок внутрь», повтор строкой таблицы = опц. по природе, инлайн-ниша `{{x?}}Датчик: {{x}}{{/x?}}`. Убрать упоминания `{{?x}}`/`{{opt_x}}`.

- [ ] **Step 2: Полный gate** — `./run check` (при «relation … does not exist» — миграция тест-БД, см. Global Constraints). Ожидание: style/phpstan/unit/functional зелёные; повтор-тесты (commission/process/recommendations/conclusion) зелёные; Optionality-тест зелёный.

- [ ] **Step 3: Reports functional-смоук** — генерация полного отчёта → docx (существующий `ReportWorkflowTest`/download-тест); отчёт без опц-блока → файл есть; без строгого блока → предупреждение (missing), файла нет.

- [ ] **Step 4: Commit (апрув)** — `контракт плейсхолдеров: 4 формы, ?=необязательно; движок унифицирован`.

---

## Self-review (проведён)

- **Покрытие спеки:** синтаксис-контракт → Task 1/2/4/6; удаление `{{?x}}`/`{{opt_x}}` → Task 5 (+заметка, что opt_x спец-кода не имел); строгий пустой повтор→missing → Task 2; инлайн/блок авто-детект → Task 4; предсказуемость (нет коллизии) → следствие Task 5; повтор строкой = опц. по природе → Global Constraints (не трогаем); миграция .docx → вне scope (пользователь); контракт-док → Task 7; XLSX/фото → вне scope (спека, «Границы»).
- **Плейсхолдеры-заглушки:** нет; тест-код и направление реализации даны.
- **Согласованность типов:** `parse()['blocks']` меняет форму в Task 1 (`['optional'=>bool,'values'=>list]`) — Task 2/3 это потребляют; `segments` убирается в Task 5; `inlineRegions` вводится Task 4 и потребляется render.
- **Открытая мелочь:** инлайн-подполя внутри СТРОК повтора (`resolveRowSegments`) — редкая ниша, в Task 5 удаляется; если понадобится — отдельная задача (в акты не входит).
