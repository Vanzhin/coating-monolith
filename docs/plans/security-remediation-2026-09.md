# Security remediation — сентябрь 2026

Мастер-план по итогам повторного security-роя (35 агентов, 5 измерений, состязательная
верификация каждой находки) по **текущему main** от 2026-09-16. **25 подтверждено, 4 отсеяно.**
Все боевые HIGH дополнительно **перепроверены вручную по коду** (см. пометки «verified»).

Прошлые ветки `security/fix-1/2/3` (реализованы 2026-09-02, НЕ смёржены, планы удалены) — 13 дней
устарели, main с тех пор оброс кодом. Использовать как референс, но **реализовывать заново** по
текущему состоянию.

Флоу: три волны = три отдельных деплоя (по правилу «многоэтапные задачи — отдельные планы»).
При старте каждой волны развернуть её в самостоятельный план `docs/plans/security-fix-N-*.md`,
свою ветку от main, свои тесты. Порядок: **Волна 1 (боевые HIGH) → Волна 2 (CSRF) → Волна 3 (hardening)**.

## Отсеяно верификацией (4 — не чинить)
- Proposals `RemoveItemAction` — дефект структуры есть, но путь недостижим (id элемента ≠ id заявки).
- Certificates Issuer `#[IsGranted('ROLE_ADMIN')]` на read-only GET — не эксплуатируется (нарушает конвенцию, но не дыра; можно причесать в Волне 2 заодно).
- Clickjacking POST-экшенов — убит `cookie_samesite: lax`.
- Unclamped-пагинация `/api/document/list` — заявленный путь не в том контексте.

---

## Волна 1 — Изоляция арендаторов (боевые HIGH). СРОЧНО.

Ломают изоляцию пользователей на живых коммерческих данных. Делать первой.

### #1 Proposals: тавтологичный гейт владения (owner===owner) — VERIFIED
Любой залогиненный не-админ правит/удаляет/скачивает чужую заявку (номера, цены, слои, проект).
- **Корень (по коду):** `UpdateAction.php:64` пишет `$inputData['ownerId'] = $proposal->getOwnerId()`
  (owner ЗАГРУЖЕННОЙ чужой заявки) → в DTO (`:69`) → `UpdateGeneralProposalInfoCommandHandler.php:35-38`
  шлёт `$dto->ownerId` как «актора» в `canUpdateGeneralProposalInfo($userId,$id)`, а тот грузит ту же
  заявку и возвращает `isOwnedBy($userId)` → всегда true. Те же грабли:
  `RemoveGeneralProposalInfoCommandHandler.php:24`, `CreateProposalDocumentFileCommandHandler.php:24`.
- **Фикс (Application-AccessControl, по конвенции проекта):**
  - `GeneralProposalInfoAccessControl` резолвит текущего пользователя из `AuthUserFetcherInterface`,
    НЕ принимает `userId` от вызывающего. Методы `canEdit(GeneralProposalInfo $loaded)` /
    `canDelete(...)` берут **уже загруженный** агрегат, предикат — доменный `isOwnedBy(currentUser)`.
  - Хендлеры: убрать проброс `$dto->ownerId` как актора; грузить агрегат, передавать его в гейт.
  - `UpdateAction.php:64` — удалить poisoning `ownerId` (актор не приходит из формы).
  - Отказ — `ForbiddenException` (не `AssertService::true(...,'Запрещено.')`).

### #2 Proposals: GET edit/clone без гейта чтения — VERIFIED
`GET /cabinet/proposals/{чужой}/edit` рендерит чужую заявку; `/clone` копирует её (клон наследует
ownerId жертвы).
- **Фикс:** `canView(GeneralProposalInfo)` в том же AccessControl, вызывать перед рендером read-пути
  (`UpdateAction` GET-ветка, query-хендлер) и в `CloneAction` перед копированием. Owner клона =
  текущий пользователь, не источника. Отказ — `ForbiddenException`.

### #3 Documents `/api/document/bulk-add`: ноль авторизации + index injection + сырой NDJSON — VERIFIED
Любой JWT-холдер пишет/вайпает произвольный ES-индекс (`AddBulkAction.php:27` берёт `db_name` из тела;
`BulkInsertDocumentCommandHandler` читает файл целиком; `DocumentRepository::bulk` гонит сырой NDJSON
как body, per-line `_index` переопределяет индекс). Контекст Documents не имеет ни одного AccessControl.
- **Фикс:** завести `Documents/Application/Service/AccessControl/DocumentAccessControl::canManage()`
  → `ForbiddenException`, вызвать в `BulkInsertDocumentCommandHandler` до записи. Индекс не принимать
  от клиента — хардкод/whitelist `'documents'` серверно. Тело bulk собирать серверно из
  валидированных документов, не гнать клиентский NDJSON. Лимит размера/MIME на загрузку.

**Тесты Волны 1:** функциональные — не-владелец получает 403/Forbidden на edit/delete/download/clone
чужой заявки; владелец и админ — успех. bulk-add без прав → Forbidden; индекс из тела игнорируется.

---

## Волна 2 — CSRF и небезопасные методы (HIGH → LOW). VERIFIED (паттерн).

Систематический state-changing GET без CSRF-токена и без `methods:['POST']`. Общий триггер —
`components/delete_modal.html.twig` удаляет через `<a href>` (GET-ссылка). При `SameSite=Lax`
top-level GET уносит куки → CSRF по клику жертвы-админа.

- **Худший инстанс (HIGH):** удаление системы покрытий `CoatingSystem/RemoveAction.php` (без methods).
- **MEDIUM/LOW:** `Coating/DeleteAction`, `Certificates Document/DeleteAction`, `Issuer/DeleteAction`,
  `ChemicalResistance Substance/DeleteAction`, `Assessment/DeleteFromSubstanceAction` (`GET,POST` —
  регресс против соседнего POST-only), `Proposals DeleteAction`/`RemoveItemAction`/`CloneAction`,
  `Manufacturer ManufacturerController:100`.
- **Отдельно:** `Users/CreateChannelAction` (`methods:['GET','POST']`) — GET-ветка создаёт канал минуя
  CSRF (вектор для #6 OTP). Удалить GET-ветку, оставить POST с CSRF.
- **Фикс:** `methods:['POST']` на каждый деструктивный роут + перевести триггер удаления в общем
  `delete_modal.html.twig` (и per-context списках) с GET `<a href>` на **POST-форму с `csrf_token`**.
  Заодно причесать Issuer read-only `#[IsGranted]` под конвенцию (авторизация в Application).
- **Развилка (нужно решение):** глобальный `CsrfRequestSubscriber` (как в прошлой ветке fix-2, проверял
  intention на всех mutation-роутах, EXEMPT api/webhook) **vs** точечные form-CSRF на каждом роуте.
  Глобальный — надёжнее и меньше шансов забыть роут, но крупнее и трогает fetch-обёртку на фронте.
- **Тесты:** роут отвергает GET (405); POST без валидного токена → 403; с токеном → успех. JS-обёртка
  CSRF (если глобальный сабскрайбер) — браузерный смоук.

---

## Волна 3 — Hardening и disclosure (MEDIUM/LOW).

### #5 ExceptionListener сливает сообщения системных исключений в JSON-проде (MED) — verified
`ExceptionListener.php:51` безусловно кладёт `$exception->getMessage()` в JSON-ветке; kernel.debug-гейт
прячет только file/line/trace. Утечка схемы БД/ответов SMTP/внутренних классов.
- **Фикс:** для не-`AppException`/не-`HttpException` при `kernel.debug=false` отдавать обезличенное
  «Internal Server Error»; реальный message — только под debug.

### #6 Брутфорс OTP верификации канала (MED)
6 цифр (900k), TTL 300с, токен НЕ ротируется при неверной попытке, нет rate-limit/локаута
(`TokenService.php:72`). Плюс `CreateChannelAction` принимает любой `value` (можно чужой контакт).
- **Фикс:** инвалидировать/ротировать токен при неверной попытке ИЛИ счётчик попыток + локаут канала;
  RateLimiterFactory на `ChannelVerificationAction`; при создании канала требовать принадлежность
  контакта. (Связано с Волной 2 — GET-ветка CreateChannel.)

### #7 Нет login throttling (MED) — verified
Ни `login` (json_login `/api/auth/token/login`), ни `main` (form_login) firewall не имеют
`login_throttling`. Брутфорс паролей без лимита.
- **Фикс:** `login_throttling` (max_attempts + interval) на обоих firewall в `security.yaml`.

### #8 User enumeration на регистрации (MED) — verified. ЧАСТИЧНО закрыто.
`/sign-up` для существующего email отдаёт отличимый flash «Пользователь с такой почтой уже существует».
- **Уже сделано** (ветка `feat/registration-anti-bot`, ждёт мержа): rate-limit `registration_per_ip` +
  нейтральный ответ анти-бота. **Остаётся:** нейтрализовать ответ при существующем email
  (единый ответ независимо от существования, либо письмо-подтверждение вместо мгновенной ошибки) —
  `CreateUserCommandHandler.php:22` кидает отличимую AppException, `RegistrationController` её показывает.

### #9 JWT refresh не single_use и без отзыва (MED) — verified
`gesdinet_jwt_refresh_token.yaml`: `single_use` не задан (дефолт false), ttl 7 суток; нет
denylist/отзыва/API-logout; ip-claim фиктивен (кладётся, но не сверяется).
- **Фикс:** `single_use: true` + ротация; denylist/отзыв (jti) + API-logout, инвалидирующий refresh.
  **Требует координации с API-клиентами** (single_use ломает параллельный refresh) — обсудить.

### #10 Blind SSRF: endpoint Web Push без allowlist (LOW) — verified
`PushSubscription.php:26` отвергает только пустую строку; `WebPushNotifier` шлёт server-side POST на
любой endpoint (напр. `http://169.254.169.254/...`).
- **Фикс:** доменный инвариант в VO `PushSubscription` — endpoint только https к хосту из набора
  ожидаемых push-сервисов.

### #11 Реальный APP_SECRET в `.env.example` (LOW) — verified
`.env.example:19` содержит настоящий 32-hex секрет (= dev `app/.env`), не плейсхолдер.
- **Фикс:** заменить на плейсхолдер (`APP_SECRET=change_me`), реальный — только в окружении/Symfony
  secrets. **РОТИРОВАТЬ APP_SECRET на проде** — тем более что в ходе этого роя один verify-агент
  выгреб живой секрет в свой транскрипт (materialization), т.е. считать его скомпрометированным.
  (Смягчение: remember_me подписывает ещё и хэш пароля; CSRF-токены Symfony не HMAC от секрета —
  прямой форжи не подтверждено, но ротация обязательна.)

---

## Развилки для владельца (до старта кода)
1. Волна 2: глобальный `CsrfRequestSubscriber` vs точечные form-CSRF? (рекомендую глобальный —
   меньше шансов пропустить роут).
2. Волна 3 #9: включаем `single_use` refresh + denylist сейчас (нужна координация с API-клиентами) или откладываем?
3. #8: нейтральный ответ на существующий email vs переход на письмо-подтверждение (двойной opt-in)?
4. Порядок деплоев подтвердить: 1 → 2 → 3.

Связано: [[project_security_audit_2026_09]] (прошлый аудит + ветки fix-1/2/3), [[reference_test_run_env]].
