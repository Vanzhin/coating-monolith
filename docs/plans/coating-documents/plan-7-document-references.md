# План 7 — Референсы документа: имена вместо UUID, оба типа (система/покрытие), модалка-превью

Самодостаточный план. Зависит от `plan-2-document.md` (агрегат `Document`, VO `Reference`,
enum `ReferenceType`). **Делит новые Coatings-эндпоинты (system by-ids + system preview) с
`plan-6-document-list-facets.md`** — кто первым деплоится, тот их и добавляет; второй переиспользует.
Меню — `plan-5-nav-groups.md`. Отдельный деплой.

## Проблема

На форме документа (`admin/certificate/document/form.html.twig`) поле «Системы» показывает сырой
UUID / JSON `[{"value":"<uuid>","id":"<uuid>"}]` и умеет прикрутить только `CoatingSystem`.

Корень:
- `DocumentMapper::buildInputDataFromDto` НЕ резолвит названия (кросс-контекст) → шаблон рендерит
  `<option value="{{ ref.id }}">{{ ref.id }}</option>` → меткой чипа становится UUID.
- `DocumentMapper::references()` хардкодит `new Reference(ReferenceType::CoatingSystem, ...)` —
  покрытие прикрутить нельзя, хотя домен (`ReferenceType::Coating`) умеет.

## Ограничение (определяет решение)

Зависимость строго `Coatings → Certificates` (гейт-гейтвей заморозки). Резолвить названия/превью
систем/покрытий на бэке внутри Certificates НЕЛЬЗЯ — это цикл. Значит названия и превью тянем
**из браузера** по HTTP-эндпоинтам Coatings (как уже гидрируются чипы тегов/покрытий на списке систем,
и как форма уже дергает suggest). Никакого `Certificates → Coatings` в PHP.

## Цель

- одна кнопка **«Добавить ссылку»** → добавляет строку; в строке селектор типа (Система / Покрытие,
  расширяемо) + suggest-поиск нужного типа → прикрепить;
- оба типа сохраняются и грузятся;
- префилл-строки показывают **название** (гидрация id→title через by-ids), не UUID;
- клик по референсу → модалка-превью (система: fragment endpoint; покрытие: существующий).

## Шаги

### Coatings (shared с plan-6) — браузерные эндпоинты
1. `CoatingSystem/ByIdsAction.php` → `app_cabinet_coating_system_by_ids` → `{items:[{id,title}]}`
   (query `GetCoatingSystemsByIds`; зеркаль Coating `ByIdsAction`). Coating by-ids уже есть.
2. `CoatingSystem/PreviewAction.php` → `app_cabinet_coating_system_preview` → HTML-фрагмент модалки
   (зеркаль Coating `PreviewAction` + его шаблон). Coating preview уже есть.

### Certificates — маппер
3. `Certificates/Infrastructure/Mapper/DocumentMapper.php`:
   - `references(array $input)`: читать `references[][type]` + `references[][id]`; `ReferenceType::from($type)`
     (валидировать тип из enum → иначе `AppException`); собрать `Reference($type, Uuid)`. Убрать хардкод
     CoatingSystem. Пустой id — пропуск; невалидный uuid/type — `AppException`.
   - `buildInputDataFromDto(DocumentDTO)`: `references` → `[{type: ref.referenceType, id: ref.referenceId}]`
     для ВСЕХ типов (не только систем). Названия НЕ резолвим — метку подтянет JS by-ids.

### Форма
4. `admin/certificate/document/form.html.twig` — блок references:
   - одна кнопка «Добавить ссылку» (было «Добавить систему»); список строк + `<template>` для новой.
   - строка: `<select name="references[][type]">` из `ReferenceType::cases()` (`label()`); typeahead
     `<select name="references[][id]">`; тип управляет активным suggest-эндпоинтом.
   - эндпоинты через data-values на контейнере: suggest система → `app_cabinet_coating_system_suggest`,
     покрытие → `app_cabinet_coating_coating_suggest`; by-ids система → `app_cabinet_coating_system_by_ids`
     (новый), покрытие → `app_cabinet_coating_coating_by_ids`.
   - префилл: type+id проставлены; JS гидрирует title.
   - референс кликается → превью: система → `app_cabinet_coating_system_preview` (fragment),
     покрытие → `app_cabinet_coating_coating_preview`.
   - подсказка «Нужна хотя бы одна ссылка».

### JS
5. `document_references_controller.js` расширить:
   - add: клон строки (одна кнопка), enforce ≥1;
   - per-row: смена типа → сменить активный suggest-эндпоинт `async-typeahead` + очистить выбор;
   - on load: для каждой префилл-строки дернуть by-ids соответствующего типа → подставить title
     (через `async-typeahead#selectItem(id, title)`);
   - клик по выбранному чипу → открыть fragment-модалку соответствующего типа.
6. `async_typeahead_controller.js`: поддержать динамическую смену endpoint + программную очистку
   (`selectItem` уже есть; гидрация через него после by-ids).
7. Fragment-loader системы: `coating_system_preview_loader_controller.js` (зеркаль
   `coating_preview_loader`) ИЛИ обобщить `coating-preview-loader` на произвольный endpoint-value.
   Зафиксировать при реализации.

## Тесты
- Unit `DocumentMapper` round-trip: `buildInputDataFromDto` → `references()` и обратно, оба типа;
  невалидный type/uuid → `AppException`.
- Functional: create/update документа со ссылкой-покрытием и ссылкой-системой (реальная БД, jsonb).
- Functional Coatings: `CoatingSystem ByIdsAction` (JSON) и `PreviewAction` (фрагмент).

## Проверка
- Unit на хосте; functional в контейнере; cs-fixer/phpstan. `cd app && yarn dev`.
- Ручной прогон: «Добавить ссылку» → выбрать тип → найти → прикрепить; переоткрыть форму (имена,
  не UUID); клик → модалка; сохранить/переоткрыть; обе комбинации типов.
