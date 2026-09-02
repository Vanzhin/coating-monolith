# Security Fix 3 — Hardening (medium/low)

Статус: план готов, реализация не начата.
Ветка: `security/fix-3-hardening` (от `main`).
Соседние планы: [security-fix-1-tenant-isolation.md](security-fix-1-tenant-isolation.md), [security-fix-2-csrf-get-mutations.md](security-fix-2-csrf-get-mutations.md).

## Контекст
Шесть hardening-пунктов из аудита. Ни один не даёт прямой захват, но каждый расширяет поверхность/усиливает
другие дыры. Стек (из `composer.json`): Symfony 7.0.*, `security-bundle` 7.0.7, `symfony/rate-limiter` 7.0.*,
`lexik/jwt-authentication-bundle` 2.21.0, `gesdinet/jwt-refresh-token-bundle` 1.3.0. Edge — **Caddy** (`infra/Caddyfile`),
не nginx (`docker/nginx` — только логи).

Часть — config-only (A, B1/B2), часть — код (C, D, E, F).

---

## A — Нет троттлинга логина (form_login + JWT)  [MEDIUM]

**Сейчас:** `config/packages/security.yaml` — ни у одного firewall нет `login_throttling` (проверено по всему файлу).
`main` (form_login, `:38-42`), `login` (json_login→JWT, `:15-22`). Кастомный `LoginFormAuthenticator::authenticate()`
возвращает `Passport` с `UserBadge`+`PasswordCredentials` → `LoginThrottlingListener` подцепится.

**Изменение** (`login_throttling` нативен в security-bundle 7.0, storage `cache.app`/Redis):
```yaml
    login:
      pattern: ^/api/auth/token/login
      stateless: true
      login_throttling:
        max_attempts: 5
        interval: '15 minutes'
      json_login: { ... }        # без изменений

    main:
      form_login: { ... }
      login_throttling:
        max_attempts: 5
        interval: '15 minutes'
      lazy: true
      # остальное без изменений
```
`max_attempts` — per (username+IP); бандл авто-добавляет глобальный per-IP лимит `5 × max_attempts`. Доп. сервисов не нужно.

**Риски:** работает и на `stateless` login (storage не сессия). Легитимный юзер под 5 попыток не задет.

**Проверка:** 6 неверных POST на `/api/auth/token/login` с одного клиента → 6-й = 429 + `Retry-After`. Аналогично форма `app_login`. `bin/console debug:config security firewalls`.

---

## B — JWT: refresh долгоживущий, не ротируется, без отзыва  [MEDIUM/LOW]

**Сейчас:**
- `lexik_jwt_authentication.yaml:5` `token_ttl: 3600` (access 1ч).
- `gesdinet_jwt_refresh_token.yaml`: `ttl: 604800` (7 дней), нет `single_use`, `ttl_update`, `logout_firewall`.
  `App\Users\Domain\Entity\RefreshToken` — пустой наследник базового класса.
- Проверено по установленным бандлам: gesdinet 1.3.0 поддерживает `single_use`/`ttl_update`/`logout_firewall`; lexik 2.21 — `token_ttl`/`clock_skew`.

**Изменение (config-only, рекомендуемый минимум):**
```yaml
# gesdinet_jwt_refresh_token.yaml
gesdinet_jwt_refresh_token:
  ttl: 172800            # 2 дня вместо 7
  single_use: true       # ротация: старый refresh инвалидируется, выдаётся новый
  ttl_update: false      # жёсткий 2-дневный потолок
  # остальное без изменений
```
```yaml
# lexik_jwt_authentication.yaml
    token_ttl: 900         # 15 мин вместо 3600
```

**Отзыв (развилка, не в минимум):**
- Refresh на logout: JWT-firewalls `stateless`, logout-события там нет; `logout_firewall` вяжется только к `main`
  (сессионный, JWT не выдаёт). Реальный отзыв refresh — код/ops: `refresh-token manager ->delete()` или
  `bin/console gesdinet:jwt:revoke <token>`. С `single_use: true` окно реплея схлопывается до одного использования.
- Access-токен: самодостаточен, конфигом не отзывается. Нужен код: denylist-подписчик на `lexik...on_jwt_decoded`
  (`$event->markAsInvalid()` при denylisted `jti`/user, Redis TTL = остаток жизни) **или** per-user «token version»
  в `JWTCreatedListener` (`src/Shared/Application/EventListener/JWTCreatedListener.php`), сверяемый на decode.

**Рекомендация:** сделать B1 (`single_use`+`ttl 172800`) и B2 (`token_ttl 900`) — три строки конфига убирают
реплей/долгую жизнь. Denylist (истинный per-token отзыв) — отдельной задачей.

**Риски (флаг):** `single_use: true` **ломает клиентов, реюзящих refresh** (параллельные вкладки: второй запрос
с уже ротированным токеном → 401 → форс-релогин). Согласовать с мобилкой/SPA. Короткий `token_ttl` → чаще рефреш;
убедиться, что авто-refresh петля есть (есть, firewall `api_token_refresh`).

**Проверка:** получить refresh → рефрешнуть (ок, новый refresh) → рефрешнуть **старым** → 401 (доказ. ротации). Access >900с → 401.

---

## C — ExceptionListener течёт сырым message JSON-клиентам в проде  [LOW]

**Сейчас:** `src/Shared/Infrastructure/EventListener/Exception/ExceptionListener.php:51` — message ставится
**вне** гейта `kernel.debug` (гейт с `:53`), т.е. сырой message любого throwable уходит JSON-клиенту, включая прод-5xx
(DBAL-исключения тащат полный SQL со схемой). `HttpExceptionInterface` уже импортирован (`:12`). User-facing —
`App\Shared\Infrastructure\Exception\AppException` (default 422, русский месседж).

**Изменение** (в `exceptionToArray`): для не-AppException/не-HttpException при `kernel.debug=false` — generic:
```php
use App\Shared\Infrastructure\Exception\AppException;   // добавить

public function exceptionToArray(\Throwable $exception): array
{
    $isDebug = (bool) $this->containerBag->get('kernel.debug');
    $isUserFacing = $exception instanceof AppException || $exception instanceof HttpExceptionInterface;

    $data = ['message' => ($isUserFacing || $isDebug) ? $exception->getMessage() : 'Внутренняя ошибка сервера.'];

    if ($isDebug) {
        $data += ['file' => $exception->getFile(), 'line' => $exception->getLine(), 'trace' => $exception->getTrace()];
    }
    return $data;
}
```

**Риски:** меняется только message для сырых 5xx в проде; AppException 422/403/404 и их месседжи не тронуты.
Остаточно: некоторые `HttpException` месседжи эхуют путь/метод (`NotFoundHttpException`) — при желании занулять отдельно (флаг, не делаем ради минимальности). Downstream `ResponseListener` (kernel.response prio 200) сохраняет поле `message`.

**Проверка:** `APP_DEBUG=0`, JSON-эндпоинт кидает сырой `\RuntimeException('secret dsn')` → `message == 'Внутренняя ошибка сервера.'`, 500, без `file`/`trace`. `AppException('...')` → месседж сохранён, 422. `APP_DEBUG=1` — полный трейс (dev не тронут).

---

## D — Энумерация юзеров на регистрации  [LOW]

**Сейчас:** `CreateUser/CreateUserCommandHandler.php:22-24` кидает `AppException('Пользователь с такой почтой уже существует.')`;
`RegistrationController.php:52-60` ловит и флешит отличимый месседж (`security/register.html.twig:25-27`). Успех при этом
**авто-логинит** нового юзера (`RegistrationController:66-70`). Эталон анти-энумерации: `LoginLinkAction.php:55-67`
(одинаковый нейтральный экран, письмо шлётся только если юзер есть).

**Изменение (нейтральный ответ, минимум).** Напряжение: нейтральность несовместима с мгновенным авто-логином (ветки
должны быть неотличимы). Минимальная правка контроллера (заменить `:54-60`):
```php
} catch (AppException $e) {
    // Анти-энумерация: не подтверждаем существование аккаунта.
    // Существующему адресу — письмо «вы уже зарегистрированы» (out-of-band); экран одинаков. Ср. LoginLinkAction.
    $this->addFlash('register_success', 'Проверьте почту — мы отправили дальнейшие инструкции на указанный адрес.');
    return $this->render('security/register.html.twig', ['registrationForm' => $form->createView()]);
}
```
Ветка нового юзера должна показать **тот же** `register_success` (или сохранить авто-логин — выбрать одно;
смешение «вы вошли» vs «проверьте почту» возвращает оракул).

**Риски (флаг — продуктовое решение):** истинная нейтральность несовместима с «мгновенно залогинить нового». Не менять
поведение логина-на-регистрации молча. Отдельный `emailListValidator` (`:44-50`) — другой оракул (allow-list), вне
скоупа. DB unique constraint на email обязан остаться (дубль не создаётся даже при нейтральном месседже).

**Проверка:** сабмит существующего и свежего email → одинаковый статус/экран (без строки «уже существует»). Свежий email всё ещё создаёт аккаунт.

---

## E — Безлимитная пагинация  [LOW]

**Сейчас:** `src/Shared/Domain/Repository/Pager.php` — потолка нет (`DEFAULT_LIMIT=10`; `fromPage`/`getLimit` отдают
`perPage` как есть). Оба недоверенных вызова кормят raw query: `GetPagedDocumentsAction.php:29,34` и
`Substance/ListAction.php:28,32` (`(int)$request->query->get('limit')` → `Pager::fromPage`). `?limit=1000000` честно исполняется.

**Изменение** — клампить в **одном месте, конструкторе `Pager`** (наследуют все вызовы, включая прямой `new Pager`):
```php
readonly class Pager
{
    public const DEFAULT_LIMIT = 10;
    public const DEFAULT_PAGE = 1;
    public const MAX_LIMIT = 100;          // 10× дефолта

    public int $perPage;                   // де-промоут ради клампа
    public ?int $total_pages;

    public function __construct(public int $page, int $perPage, public ?int $total_items = null)
    {
        $this->perPage = min(max($perPage, 0), self::MAX_LIMIT);
        $this->total_pages = $this->total_items ? (int) ceil($this->total_items / max(1, $this->perPage)) : null;
    }
```
`max(1, ...)` попутно убирает latent divide-by-zero при `perPage=0`.

**Риски:** клиент с `limit>100` тихо получит 100. Грепнуть все `Pager::fromPage`/`new Pager` перед деплоем (только два
контроллера читают `limit` из юзера; внутренние зовут фикс. размеры). Опционально клампить и `page` (негативный page → негативный offset).

**Проверка:** unit `tests/Unit/Shared/Domain/Repository/PagerTest.php` — `new Pager(1,100000)->getLimit()===100`; `fromPage(1,5)===5`; `fromPage()===10`; `new Pager(1,0)` не делит на ноль. Functional: `GET /api/document/list?limit=100000` ≤100 строк.

---

## F — Нет security-заголовков (clickjacking/CSP)  [LOW, усиливает CSRF]

**Сейчас:** ни `X-Frame-Options`, ни `CSP`, ни `HSTS`, ни `X-Content-Type-Options`, ни `Referrer-Policy` (греп по `config/`+`docker/` пусто). Edge — Caddy, `infra/Caddyfile` голый. Ассеты **same-origin** (Webpack Encore, Bootstrap Icons локально, без CDN). Есть **один inline `<script>`** в `<head>` (`base.html.twig:23-31`, FOUC-тема) — ключевое ограничение CSP.

**Изменение** — заголовки в `kernel.response`-подписчике (стиль проекта: `ResponseListener`/`ExceptionListener` на `#[AsEventListener]`).
Файл `src/Shared/Infrastructure/EventListener/Response/SecurityHeadersListener.php`:
```php
#[AsEventListener]
public function onKernelResponse(ResponseEvent $event): void
{
    if (!$event->isMainRequest()) return;
    $h = $event->getResponse()->headers;
    $h->set('X-Frame-Options', 'DENY');
    $h->set('X-Content-Type-Options', 'nosniff');
    $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    $h->set('Content-Security-Policy', implode('; ', [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline'",   // нужно для FOUC-темы + inline Bootstrap/Stimulus
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data:",
        "font-src 'self' data:",
        "connect-src 'self'",
        "frame-ancestors 'none'",
        "base-uri 'self'",
        "form-action 'self'",
    ]));
}
```

**Риски (флаг):**
- Строгий `script-src 'self'` **сломает** FOUC-`<script>` (`base.html.twig:23-31`) → вспышка темы / CSP-ошибка. Поэтому `'unsafe-inline'` в baseline. Ужесточать позже через per-request nonce (больший объём).
- `style-src 'unsafe-inline'` — Bootstrap 5.3/Stimulus ставят инлайн-стили; убирать рискованно.
- **Рекомендация: первый деплой — `Content-Security-Policy-Report-Only`** (тот же стринг, другой заголовок), собрать нарушения, потом enforce.
- HSTS осмыслен только по HTTPS (TLS терминируется выше Caddy). Подтвердить перед enforce; `includeSubDomains` может задеть HTTP-сабдомены.
- `X-Frame-Options: DENY` + `frame-ancestors 'none'` (пояс+подтяжки). Альтернатива — `header` в Caddyfile, но политика уедет из app-кода; **не дублировать** (два CSP-заголовка = непредсказуемо).

**Проверка:** `curl -sI` (или WebTestCase на `headers`) → все пять заголовков на HTML и JSON. Браузер в Report-Only: ноль нарушений против Bootstrap/Stimulus/Encore/темы перед enforce.

---

## Config-only vs код
- **Config-only:** A (security.yaml), B1/B2 (lexik + gesdinet).
- **Код:** C (ExceptionListener), D (контроллер + UX-решение), E (Pager), F (новый подписчик), B3 denylist (отдельно).

## Порядок реализации (по шагам)
1. A — throttling (config) + проверка 429.
2. B1/B2 — refresh single_use + ttl, access ttl (config) + тест ротации. Согласовать `single_use` с клиентами.
3. C — ExceptionListener + тесты.
4. E — Pager clamp + unit.
5. D — энумерация (нужно продуктовое решение по авто-логину — развилка к пользователю).
6. F — SecurityHeadersListener, сначала Report-Only.
