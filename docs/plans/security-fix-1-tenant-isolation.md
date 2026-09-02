# Security Fix 1 — Изоляция пользователей (боевые high)

Статус: план готов, реализация не начата.
Ветка: `security/fix-1-tenant-isolation` (от `main`).
Соседние планы: [security-fix-2-csrf-get-mutations.md](security-fix-2-csrf-get-mutations.md), [security-fix-3-hardening.md](security-fix-3-hardening.md).

## Контекст

Аудит безопасности (двойной рой + состязательная верификация) нашёл три боевые high-дыры,
ломающие изоляцию между пользователями. Все три — про авторизацию, чинятся на бэке существующими
примитивами (`AuthUserFetcherInterface`, `AccessGuard`, `ForbiddenException`, `RateLimiterFactory`),
новой инфраструктуры не требуют. Один деплой.

Канон авторизации (CLAUDE.md): текущий юзер — из `AuthUserFetcherInterface::getAuthUserId()`;
capability — `AccessGuard::isManager()`; resource-based — `canEdit($loadedEntity)` = `isManager() ||
$entity->isOwnedBy(currentUserId)`; отказ — `ForbiddenException` (403). Проверку получает **уже
загруженный объект**, не id (иначе двойной фетч + TOCTOU).

## Что закрываем

- **BUG-1 Proposals: проверка владельца — тавтология.** `GeneralProposalInfoAccessControl::canUpdate/canDelete`
  получают в качестве «кто спрашивает» ownerId **самого ресурса**, а не текущего юзера → `owner === owner`
  всегда true. Любой залогиненный правит/удаляет/скачивает/клонирует чужую заявку.
- **BUG-2 `/api/document/bulk-add` без авторизации вообще.** Контекст Documents не имеет AccessControl;
  любой JWT-юзер пишет/удаляет в произвольный ES-индекс (attacker-controlled `db_name` + сырой NDJSON).
- **BUG-3 OTP верификации канала брутфорсится.** 6-значный код (900 000 вариантов), TTL 300 с, нет
  rate-limit, нет локаута, токен переживает неверные попытки.

---

## BUG-1 — Proposals ownership tautology

### Текущее состояние (заземлено)

- `src/Proposals/Application/Service/AccessControl/GeneralProposalInfoAccessControl.php`
  - ctor (`:17-20`) инжектит только `AuthChecker` + репозиторий — **не** `AuthUserFetcherInterface`,
    то есть класс не знает, кто текущий юзер.
  - `canDeleteGeneralProposalInfo(string $userId, string $proposalInfoId)` (`:26`), `canUpdate...` (`:40`)
    — «кто спрашивает» приходит **параметром от вызывающего**; внутри `return $proposalInfo->isOwnedBy($userId)`
    (`:34`, `:45-48`) + повторный `findOneById` (двойной фетч).
- `src/Proposals/Domain/Aggregate/Proposal/GeneralProposalInfo.php:254-257`
  `isOwnedBy($ownerId) { return $this->ownerId === $ownerId; }` — предикат верный, аргумент кормят не тот.
- Тавтологичные вызовы (передают ownerId самого ресурса):
  - `RemoveGeneralProposalInfoCommandHandler.php:24-30` → `$proposalInfo->getOwnerId()`.
  - `RemoveGeneralProposalInfoItem/RemoveGeneralProposalInfoItemCommandHandler.php:24-30` → `$proposalInfoItem->getProposal()->getOwnerId()`.
  - `CreateProposalDocumentFile/CreateProposalDocumentFileCommandHandler.php:24-30` → `$command->document->getProposalInfo()->getOwnerId()` (путь DownloadAction).
  - `UpdateGeneralProposalInfo/UpdateGeneralProposalInfoCommandHandler.php:35-40` → `$dto->ownerId`, а он заполняется из ресурса в `UpdateAction.php:64` (`$inputData['ownerId'] = $proposal->getOwnerId();`).
- `CloneAction.php:31-53` — **авторизации нет вообще**: читает любую заявку, кладёт в DTO исходный ownerId,
  диспатчит `CreateGeneralProposalInfoCommand` (хендлер без гейта), клон создаётся владельцем **исходного** юзера.
- `DownloadAction.php:33-49` — своей авторизации нет, полагается на тавтологичный `CreateProposalDocumentFileCommandHandler`.
- Важно: сейчас отказ идёт через `AssertService::true(...)` → это `webmozart/assert` → бросает
  `\InvalidArgumentException`, **не** `ForbiddenException`/403. Переход на `ForbiddenException` — часть фикса.
- Эталон корректного использования: `ChannelAccessControl.php:23-35` (`canView` берёт текущего юзера через
  `$this->fetcher->getAuthUser()->getUlid()`), `CreateChannelCommandHandler.php:30` (`$this->authUserFetcher->getAuthUserId()`).

### Изменения

1. **`GeneralProposalInfoAccessControl` переписать на один метод, консультирующийся с текущим юзером и
   принимающий уже загруженный агрегат:**
   - ctor: инжектить `AuthUserFetcherInterface $fetcher` и `AccessGuard $guard` (вместо сырого `AuthChecker`/`Role::ROLE_ADMIN`).
   - `public function canEdit(GeneralProposalInfo $proposal): bool { return $this->guard->isManager() || $proposal->isOwnedBy($this->fetcher->getAuthUserId()); }`
   - убрать параметр `$userId` и внутренний `findOneById`.
2. **Хендлеры** — грузим агрегат, гейтим загруженным объектом, отказ через `ForbiddenException`:
   - `UpdateGeneralProposalInfoCommandHandler`: поднять `findOneById` выше гейта; заменить `:35-41` на
     `if (!$this->access->canEdit($generalProposalInfo)) { throw new ForbiddenException(); }`. Личность больше не берём из `dto->ownerId`.
   - `RemoveGeneralProposalInfoCommandHandler:24-30` → `canEdit($proposalInfo)`.
   - `RemoveGeneralProposalInfoItemCommandHandler:24-30` → `canEdit($proposalInfoItem->getProposal())`.
   - `CreateProposalDocumentFileCommandHandler:24-30` → `canEdit($command->document->getProposalInfo())`.
3. **`UpdateAction.php:64`** — инъекция `ownerId` остаётся только как shape для round-trip формы, но
   личностью больше не служит (хендлер берёт юзера из `AuthUserFetcher`).
4. **CloneAction** — после загрузки исходной заявки (`:34`) гейт
   `if (!$access->canEdit($proposal)) throw new ForbiddenException();`, плюс клон должен принадлежать актору:
   `dto->ownerId = $this->authUserFetcher->getAuthUserId()`. (Альтернатива: отдельный `CloneGeneralProposalInfoCommand`
   с гейтом в Application — развилка, решить при реализации; по умолчанию гейт в контроллере, как сейчас у Clone.)
5. **DownloadAction** — отдельная правка не нужна после п.2; опционально ранний `canEdit`-гейт в экшене ради чистого 403.

### Новые файлы / конфиг
Нет. Всё на существующих примитивах.

### Тесты (зеркалят src/)
- `tests/Functional/Proposals/Application/UseCase/Command/UpdateGeneralProposalInfoTest.php` — актор не-владелец не-админ ⇒ `ForbiddenException`; владелец/админ ⇒ успех. Аналогично `RemoveGeneralProposalInfoTest.php`, `RemoveGeneralProposalInfoItemTest.php`, `CreateProposalDocumentFileTest.php`.
- `tests/Functional/Proposals/Infrastructure/Controller/{CloneActionTest,DownloadActionTest}.php` — не-владелец получает отказ (клон не создан / файл не отдан).
- Разрешённый кейс — `AuthenticatesActorTrait::authenticateAsSystem()`; запрет — `PreAuthenticatedToken` с обычным `ROLE_USER` не-владельцем (паттерн из `tests/Functional/Certificates/.../DocumentUseCasesTest.php:62`).

---

## BUG-2 — /api/document/bulk-add без авторизации

### Текущее состояние (заземлено)

- `src/Documents/Infrastructure/Controller/Api/AddBulkAction.php:17` — `#[Route('/api/document/bulk-add', methods: ['POST'])]`;
  `:27` `$dbName = $request->getPayload()->get('db_name')` (attacker-controlled индекс); `:35` `new BulkInsertDocumentCommand($file->getRealPath(), $dbName)`.
- `config/packages/security.yaml:59` `^/api → IS_AUTHENTICATED` — любой JWT доходит; role-гейта в пайплайне нет.
- `BulkInsertDocumentCommandHandler.php:17-23` — гейта нет; читает файл и `documentRepository->bulkInsert($data, $command->db)`.
- `DocumentRepository.php`: `$default = 'documents'` (`:24`); `bulkInsert(...)` (`:76-82`)
  `$this->client->bulk(['index' => $dbName ?? $this->default, 'body' => $data])` — **сток**: attacker
  контролирует и индекс, и сырой NDJSON-body (per-line `_index`/`delete` → любой индекс).
- Контекст Documents **не имеет** каталога `Application/Service/AccessControl/` (остальные контексты имеют).
- Sibling `AddDocumentCommandHandler` реален, но его сток `DocumentRepository::save()` — `dd($document)` (`:84-87`),
  а web-вход `Document/AddAction.php` — todo-заглушка. То есть единственный рабочий (и незащищённый) путь записи — bulk.
- Эталон для копирования: `src/Certificates/Application/Service/AccessControl/DocumentAccessControl.php` (`canManage()` → `AccessGuard::isManager()`), использование `CreateDocumentCommandHandler.php:26-28`.

### Изменения

1. **Гейт в хендлере:** инжектить `DocumentAccessControl` в `BulkInsertDocumentCommandHandler`, в самом верху
   `__invoke` (до `file_get_contents`) — `if (!$this->access->canManage()) { throw new ForbiddenException(); }`.
2. **Перестать доверять `db_name`:** убрать чтение `db_name` (`AddBulkAction.php:27`) и второй аргумент
   `BulkInsertDocumentCommand.php:11`. `bulkInsert` пишет только в `$this->default` (`'documents'`).
   Если несколько индексов реально нужны — валидировать против allow-list известных ES-конфигов
   (`ConfigLoader::loadFromConfig`, `src/Shared/Infrastructure/Database/ES/ConfigLoader.php:19-26`; сейчас
   упоминается только `documents`), иначе `AppException`.

### Новые файлы / конфиг
- Новый `src/Documents/Application/Service/AccessControl/DocumentAccessControl.php` — зеркаль `Certificates/.../DocumentAccessControl.php` (final readonly, ctor `AccessGuard $guard`, `canManage(): bool { return $this->guard->isManager(); }`). Автовайринг подхватит, service-конфиг не нужен.
- Route/security-конфиг не трогаем (гейт в хендлере — канон).

### Тесты
- `tests/Unit/Documents/Application/Service/AccessControl/DocumentAccessControlTest.php` — `canManage()` true для менеджера, false иначе.
- `tests/Functional/Documents/Application/UseCase/Command/BulkInsertDocumentTest.php` — (a) не-админ ⇒ `ForbiddenException`, запись не произошла; (b) `authenticateAsSystem()` ⇒ bulk успешен, попал в `documents`; (c) переданный `db_name` игнорируется/отклоняется.

---

## BUG-3 — Channel OTP брутфорс

### Цифры (для контекста)
6 цифр = **900 000** вариантов (`random_int(1e5, 1e6-1)`, `TokenService.php:113-120`, `TOKEN_LENGTH=6` `:18`);
TTL **300 с** (`TOKEN_LIFETIME=300` `:17`). Один живой токен на субъект, но внутри окна — **неограниченно попыток**.

### Текущее состояние (заземлено)
- `src/Users/Infrastructure/Service/TokenService.php`: генерация `:113-120`; верификация `:51-85`, на неверном
  вводе `:72` `throw new AppException('Неверный токен верификации')` — **токен не удаляется, попытки не считаются**.
  `equals` timing-safe (`Token.php:39-42` `hash_equals`) — дыра именно в отсутствии лимита попыток.
- `Token.php` — `final readonly`, поля попыток нет; хранится в Redis (`TokenRepository`, ключ `token:<subjectId>`,
  TTL = срок токена). Счётчик попыток — отдельный Redis-ключ или rate-limiter.
- `VerifyChannel/VerifyChannelCommandHandler.php:20-26` → `Channel::verify` (`Channel.php:62-68`) → `tokenService->verifySubjectByTokenString`.
- Роут/экшен: `src/Users/Infrastructure/Controller/Channel/ChannelVerificationAction.php:33`
  (`app_user_channel_verification`), сабмит диспатчит `VerifyChannelCommand` (`:77-79`), ловит `\Exception` и
  ре-рендерит с флешем (`:85-88`). Доступен любому авторизованному (`^/user IS_AUTHENTICATED`).
- Прецедент лимитера: `framework.yaml:20-32` (`login_link_per_email` 3/1h, `login_link_per_ip` 30/1h, `sliding_window`,
  storage `cache.app`/Redis); инъекция — `LoginLinkAction.php:25-28` (`#[Autowire(service: 'limiter.login_link_per_ip')]`),
  использование `:44-53` (`->create($key)->consume()->isAccepted()`, иначе ре-рендер с алертом).

### Изменения
1. **framework.yaml** — два лимитера под `rate_limiter:` (зеркаль login-link пары):
   - `channel_verify_per_channel`: `sliding_window`, `limit: 5`, `interval: '15 minutes'` (ключ — id канала).
   - `channel_verify_per_ip`: `sliding_window`, `limit: 20`, `interval: '1 hour'` (ключ — client IP).
2. **`ChannelVerificationAction`** — инжектить обе фабрики `#[Autowire(service: 'limiter.channel_verify_per_channel')]` /
   `...per_ip`. В ветке `isSubmitted()&&isValid()`, до `commandBus->execute(new VerifyChannelCommand(...))`:
   `->create($channel->getId())->consume()` и `->create($request->getClientIp() ?? 'unknown')->consume()`;
   если любой `!isAccepted()` — флеш-ошибка и `render` (UX как `LoginLinkAction.php:45-53`), команду не звать.
3. **Счётчик неверных попыток в `TokenService::verifySubjectByTokenString`** (ветка `:72-74`): перед throw
   инкрементить per-subject счётчик в Redis (`token_attempts:<subjectId>`, TTL = остаток жизни токена) через
   новый метод `TokenRepositoryInterface` (`incrementFailures`/`getFailures`/`resetFailures` рядом с `TokenRepository.php:59-62`).
   На пороге (5) — `$this->removeToken($verifiable)` и throw (сожжённый токен нельзя добить, юзер запрашивает новый).
   Сброс счётчика на успехе (перед `removeToken` `:82`) и при создании нового токена (`makeToken` `:41`).
   Это защищает даже при обходе request-лимитера (распределённые IP), т.к. ключ — субъект.

### Новые файлы / конфиг
- framework.yaml: два `rate_limiter`-энтри (Symfony создаст сервисы `limiter.channel_verify_*` сам).
- `TokenRepositoryInterface` + `TokenRepository`: методы счётчика (Redis). Нового класса не требуется.

### Тесты
- `tests/Unit/Users/Infrastructure/Service/TokenServiceTest.php` — после N неверных вызовов токен удалён, дальше
  верификация падает; верный код до порога проходит; счётчик сбрасывается на успехе.
- `tests/Functional/Users/Infrastructure/Controller/Channel/ChannelVerificationActionTest.php` — превышение
  `channel_verify_per_channel` ре-рендерит форму с ошибкой и **не** верифицирует канал; корректный сабмит под лимитом верифицирует.

---

## Верификация волны
```bash
cd app && vendor/bin/phpunit tests/Unit/Documents tests/Unit/Users
# functional — в контейнере с override DATABASE_URL@manager_db / REDIS_HOST:
cd app && vendor/bin/phpunit tests/Functional/Proposals tests/Functional/Documents tests/Functional/Users
```
cs-fixer / phpstan — в контейнере (host PHP слишком новый).

## Развилки
- Clone: гейт в контроллере (по умолчанию) vs отдельный `CloneGeneralProposalInfoCommand` с гейтом в Application.
- Documents: жёстко один индекс `documents` (по умолчанию) vs allow-list ES-конфигов.

## Порядок реализации (по шагам, с показом результата)
1. BUG-1 (Proposals) — AccessControl + 4 хендлера + Clone/Download + тесты.
2. BUG-2 (Documents) — DocumentAccessControl + гейт + выпил db_name + тесты.
3. BUG-3 (Channel OTP) — лимитеры + счётчик попыток + тесты.
