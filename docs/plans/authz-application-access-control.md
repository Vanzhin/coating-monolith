# Авторизация в Application-слой + системный принципал для консоли

Ветка: `refactor/authz-application-access-control` (от `main`).

## Цель

Перенести авторизацию (кто вправе выполнить операцию) из инфраструктуры
(`#[IsGranted('ROLE_ADMIN')]` на контроллерах) в **Application-слой каждого
контекста** — по образцу уже существующих `ChannelAccessControl` /
`UserAccessControl` / `GeneralProposalInfoAccessControl` и эталона в
symfony-docker-lessons (`SkillAccessControl`, вызывается из command-хендлеров).

Принцип: **аутентификация — один раз в конфиге** (`security.yaml`:
`/cabinet → IS_AUTHENTICATED`), **авторизация — дело домена**, в его
use-case-хендлерах. Контроллеры знают только «есть юзер или нет».

## Решения (согласовано с заказчиком)

1. **`ROLE_SYSTEM`** — отдельная роль для консоли (не переиспользуем `ROLE_ADMIN`).
   `AccessControl` короткозамыкает и на систему, и на админа.
2. **Контроллерный `#[IsGranted('ROLE_ADMIN')]` — снять полностью.** Проверка
   только в Application-хендлере. Грубая аутентификация остаётся в `security.yaml`.
3. **Все контексты в одной ветке**: Certificates + Coatings + ChemicalResistance.

## Архитектура проверки

- **capability/роль** (`isAdmin`/`isSystem`) → Application, через `AuthChecker`.
- **предикат владения** (если появится) → метод домена на агрегате.
- **оркестрация решения** → `{Context}/Application/Service/AccessControl/*AccessControl`.
- **enforcement** → command/query-хендлер: `if (!$access->canX()) throw new ForbiddenException()`.

У каталога Coatings/Certificates владения по юзеру нет → правило пока чисто
capability (`isManager(): isAdmin || isSystem`). Сервис на контекст всё равно
заводим — принцип «каждый контекст решает сам» + future-proof.

## Фаза 0 — Shared-инфра

- `Shared/Domain/Security/Role`: добавить `ROLE_SYSTEM`.
- `Shared/Domain/Security/SystemUser` (`implements AuthUserInterface`) — синтетический
  принципал с фиксированным ulid и `ROLE_SYSTEM`.
- `Shared/Infrastructure/Console/ConsoleAuthenticationSubscriber` на
  `ConsoleEvents::COMMAND`: ставит пре-аутентифицированный токен с `SystemUser`
  в `TokenStorage`. Только CLI (консольные события в web не летят). Без неявного
  фолбэка в `AuthUserFetcher`.
- `Shared/Infrastructure/Exception/ForbiddenException extends AppException`
  (код 403 по умолчанию). Проверить маппинг существующим listener'ом → HTTP 403
  и ненулевой exit в CLI.
- `Shared/Application/Security/AccessGuard` (опционально) — общий хелпер
  `isManager(): bool` (`isGranted(ROLE_ADMIN) || isGranted(ROLE_SYSTEM)`), чтобы
  контекстные `AccessControl` не дублировали короткое замыкание.
- Тест-хелпер: трейт/база для функциональных хендлер-тестов, аутентифицирующая
  админа или систему (иначе тесты, диспатчащие мутацию без токена, упадут).

## Фаза 1 — Certificates (эталон)

- `DocumentAccessControl`: `canManage(): isManager()`. Вызов из
  `Create/Update/DeleteDocumentCommandHandler`.
- `IssuerAccessControl`: `canManage()` (мутация) и `canView()` (Issuer целиком
  админский → проверка и в Query-хендлере списка/suggest).
- Снять `#[IsGranted]` с Document- и Issuer-контроллеров.
- Проверить: `ImportConclusionsCommand` (консоль) создаёт документы под `SystemUser` → проходит.
- Тесты: handler — admin OK / не-админ Forbidden / система OK; функциональные — обновить.

## Фаза 2 — Coatings

Мутирующие хендлеры: Create/Update/Remove Coating, CoatingSystem, слои
(Move/RemoveLayerAt/ReplaceLayers), SurfaceTreatment, Color, Tag, Manufacturer.
`CoatingAccessControl.canManage()`. Снять `#[IsGranted]` с контроллеров Color/Tag/SurfaceTreatment/…

## Фаза 3 — ChemicalResistance

Assessment + Substance (Create/Update/Delete) → `ChemicalResistanceAccessControl.canManage()`.
Снять `#[IsGranted]` и оставшиеся контроллерные проверки.

## Проверки

`./run check` (style, phpstan, unit, functional) + twig-lint изменённых. Прогнать
консольные `ImportConclusionsCommand`/`ImportCoatingSystemsCommand` (dry, если есть).

## Развилки на потом

- Query-side visibility для остальных контекстов (сейчас view открыт → не трогаем,
  кроме Issuer).
- Ownership-семантика, если появятся не-админ-редакторы.
