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

## Кросс-контекстный доступ (ACL, единый адаптер — ациклично)

Направление строго `Coatings` (потребитель) → `Certificates` (провайдер). `Certificates` код
`Coatings` НЕ тянет. Вместо двухслойного ACL (Proposals↔Coatings, он цикличен на инфра-уровне)
берём один адаптер:

- **Доменный порт потребителя** — `Coatings/Domain/Service/SystemCertificatesGateway.php`,
  выражен в СОБСТВЕННЫХ типах Coatings (никаких типов Certificates):
  - `hasCertificates(string $systemId): bool` — для guard заморозки (see below).
  - `countBySystemIds(StringCollection $systemIds): array<string,int>` — id системы → число документов.
  - `listBySystem(string $systemId): list<SystemCertificate>`.
  - `SystemCertificate` (`Coatings/Domain/Service/`) — read-VO Coatings: `id`, `title`, `kindLabel`,
    `issuerTitle`, `issuedAt`, `expiresAt`, `isExpired`, `testStandard`, `hasFile`, `downloadUrl`.
- **Адаптер** — `Coatings/Infrastructure/Adapter/CertificatesGateway.php` `implements SystemCertificatesGateway`.
  Напрямую зависит от `Certificates\Domain\Repository\DocumentRepositoryInterface` (+ `IssuerRepositoryInterface`
  для titles, `UrlGeneratorInterface` для downloadUrl). Зовёт `findByReference`/`countByReferences`
  (jsonb-containment по `references` с `ReferenceType::CoatingSystem`), маппит `Document` → `SystemCertificate`.
  Ациклично: Coatings-инфра зависит от Certificates-домена через интерфейс; Certificates ничего про Coatings не знает.

Фасет «есть документы» тоже через порт: handler зовёт `countBySystemIds(candidateIds)` и ограничивает
finder по id (`IN`/`NOT IN`) — таблицу `certificates_document` в SQL Coatings не тянем.

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

## Заморозка сертифицированной системы целиком (write-side guard)

Если к системе привязан ≥1 документ (`Reference` типа `CoatingSystem`), её нельзя менять ВООБЩЕ —
ни слои (состав/толщина/цвет), ни подложку/подготовку/среду/название/теги. Удаление такой системы
тоже запрещено, пока документы не отвязаны/удалены.

Механизм: перед любой мутацией системы командный хендлер Coatings спрашивает порт
`hasCertificates(systemId)`; если true — `AppException` (422):
«К системе привязан сертификат — систему менять нельзя. Создайте новую систему (при необходимости
дублированием) и правьте её сколько угодно.» Без денормализованного флага — источник истины
таблица документов Certificates.

Точки-хендлеры Coatings, куда ставим guard (ВСЕ мутации системы):
- обновление системы (`UpdateCoatingSystem`: title/description/substrate/treatment/environment/теги/состав);
- операции со слоями (append/insert/remove/move, `updateLayerDft`, `replaceLayers`, смена цвета слоя),
  если они идут отдельными хендлерами (AJAX-редактирование слоёв);
- удаление системы (`DeleteCoatingSystem`).
Не трогаем только read/query. Аудит всех write-хендлеров `CoatingSystem` — часть Плана 3.

Guard выносим в один хелпер (доменный сервис `App\Coatings\Domain\Service\SystemLockGuard`,
инжектящий порт), чтобы не дублировать проверку по хендлерам. Правило кросс-контекстное, поэтому
в самом агрегате `CoatingSystem` его нет.

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
