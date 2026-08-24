# План 3 — Интеграция документов в read-модель системы (кросс-контекст `Coatings` ← `Certificates`)

Самодостаточный план. Общий контекст — `design.md`. Зависит от `plan-2-document.md`
(агрегат `Document` + его запросы существуют). Отдельный деплой, т.к. правит горячие файлы
Coatings, часть которых сейчас в незакоммиченной работе SP28.

## Цель

На стороне `Coatings` показать документы систем, читая их из `Certificates` через ACL-порт:
- **список систем** — счётчик документов (бейдж на карточке);
- **карточка/превью-модалка системы** — список документов (вид, издатель, даты, статус
  действует/просрочен, ссылка на скачивание);
- **поиск систем** — boolean-фильтр «есть документы: да/нет».

## Кросс-контекстный доступ (ACL, зеркаль Proposals→Coatings)

Направление: `Coatings` (потребитель) читает `Certificates` (провайдер). Ациклично —
`Certificates` код `Coatings` не тянет. Паттерн — как `CoatingsServiceInterface` +
`CoatingsAdapter` + `CoatingsApiInterface` + `CoatingsApi`:

- **Доменный порт потребителя** — `Coatings/Domain/Service/SystemCertificatesServiceInterface.php`,
  выражен в СОБСТВЕННЫХ типах Coatings (никаких типов Certificates):
  - `countBySystemIds(StringCollection $systemIds): array<string,int>` — id системы → число документов.
  - `listBySystem(string $systemId): array` (`list<SystemDocumentData>`).
  - `systemIdsWithDocuments(StringCollection $candidateIds): StringCollection` — для фильтра-фасета.
  - `SystemDocumentData` (`Coatings/Domain/Service/`) — плоский DTO: `id`, `title`, `kindLabel`,
    `issuerTitle`, `issuedAt`, `expiresAt`, `isExpired`, `testStandard`, `downloadUrl`.
- **Инфра-адаптер потребителя** — `Coatings/Infrastructure/Adapter/CertificatesAdapter.php`
  `implements SystemCertificatesServiceInterface`, маппит результаты провайдера → `SystemDocumentData`,
  зависит от `CertificatesApiInterface`.
- **Инфра-интерфейс API провайдера** — `Coatings/Infrastructure/Adapter/CertificatesApiInterface.php`,
  выражен в query-result типах `Certificates` (методы `documentsForSystem(string)`,
  `documentCountsForSystems(list<string>)`).
- **Реализация на стороне провайдера** — `Certificates/Infrastructure/Api/CertificatesApi.php`
  `implements CertificatesApiInterface`, через публичный фасад `Certificates\...\PublicUseCaseInteractor`
  (запросы `findByReference`/`countByReferences` из Плана 2, с `ReferenceType::CoatingSystem`;
  внутри — jsonb-containment по коллекции `references`). Биндинг — автоваулинг (один имплементор),
  как `CoatingsApi`.

Следствие many-to-many: один документ (напр. сертификат на несколько покрытий/систем) может
числиться и показываться у нескольких систем; счётчик системы = число документов, чья коллекция
`references` содержит `{CoatingSystem, <id системы>}`.

## Точки правок в Coatings

### Счётчик + список документов на карточке
1. `Application/DTO/CoatingSystems/CoatingSystemDTO.php` — добавить `public int $documentCount = 0;`
   и `public array $documents = [];` (`list<SystemDocumentData>`).
2. `Application/DTO/CoatingSystems/CoatingSystemDTOTransformer.php` — `fromEntity` принимает
   документы аргументом (как сейчас `$compliance`), т.к. агрегат `CoatingSystem` документы не знает.
3. `Application/UseCase/Query/SearchCoatingSystems/SearchCoatingSystemsQueryHandler.php` — после
   `finder->find`/`repo->findByIds` дёрнуть порт `countBySystemIds`/`listBySystem` по id систем
   страницы и прокинуть в `fromEntity` (паттерн как `$complianceBySystem`).
4. Twig `cabinet/coating/coating_system/_list_cards.html.twig` — бейдж `{{ system.documentCount }}`
   рядом с прочими бейджами; `documents: system.documents` в `payload` превью-модалки.
5. JS `app/assets/controllers/coating_system_preview_controller.js` — рендер блока документов в
   модалке (список + ссылки download + метка «просрочен»). HTML не дублировать с Twig —
   `<template>`/частичный fetch (правило проекта).

### Boolean-фасет «есть документы» (4 точки, как `environment`)
6. `Domain/Repository/CoatingSystemsFilter.php` — `public ?bool $hasDocuments = null;`.
7. `Infrastructure/Mapper/CoatingSystemListRequestMapper.php::filterFromRequest` —
   `$request->query->has('hasDocuments') ? $request->query->getBoolean('hasDocuments') : null`.
8. `Infrastructure/Search/CoatingSystemFinder.php` — применение фильтра. Чтобы НЕ тянуть таблицу
   `certificates_document` в SQL Coatings (граница контекста), делаем через порт:
   handler заранее резолвит `systemIdsWithDocuments(candidateIds)` и передаёт в finder ограничение
   по id (`cs.id IN (:ids)` при `true`, `NOT IN` при `false`).
   Альтернатива (проще, но с кросс-контекстным `EXISTS certificates_document` прямо в SQL) —
   зафиксировать при реализации; по умолчанию берём порт (чистая граница).
9. `Infrastructure/View/CoatingSystemListViewFactory.php` — echo-back `hasDocuments` +
   учесть в `activeFacetsCount`.
10. Twig `cabinet/coating/coating_system/list.html.twig` — чекбокс-фасет `hasDocuments`
    (desktop ~180-212 + mobile ~437-458, образец `environment`), `+ (hasDocuments ? 1 : 0)` в
    `activeFacetsCount` (~29-31), reset-чип `merge({hasDocuments: null, page: null})`.
    Программный сабмит — только `requestSubmit()` (память: `form.submit()` обходит chip-facets merge).

## Тесты
- Functional: `SearchCoatingSystemsQueryHandler` заполняет `documentCount`/`documents` из порта;
  фильтр `hasDocuments=true/false` отбирает корректно (через порт-стаб/реальный `Certificates`).
- Адаптер `CertificatesAdapter` — маппинг provider→`SystemDocumentData` (unit с мок-`CertificatesApiInterface`).
- Twig-снимок карточки с бейджем; активный чип фильтра.

## Проверка
- Unit на хосте; functional в контейнере; phpstan/cs-fixer.
- Ручной прогон: система с документами показывает счётчик; модалка — список со скачиванием и меткой
  просрочки; фильтр «есть документы» да/нет фильтрует; чип сбрасывается. `cd app && yarn dev`.

## Открытые мелочи
- Реализация фасета: порт (`IN/NOT IN`) vs кросс-контекстный `EXISTS` — выбрать при реализации
  (по умолчанию порт).
- `downloadUrl` в `SystemDocumentData` — генерить через роутер по имени роута `DownloadAction`
  Плана 2 (адаптер инжектит `UrlGeneratorInterface`).
