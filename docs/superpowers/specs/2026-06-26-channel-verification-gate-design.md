# Глобальный гейт верификации канала

**Статус:** draft
**Дата:** 2026-06-26

## Цель

Любой аутентифицированный пользователь без хотя бы одного верифицированного канала (`User::isActive() === false`) должен быть редиректнут на страницу верификации (`/user/channel/verification`) при любой попытке использовать функционал. Проверка выполняется в **одной центральной точке**, без рассыпания `if`-ов по контроллерам и без необходимости перечислять защищённые пути в `security.yaml`.

## Контекст

Что уже есть в проекте:

- `User::isActive` (boolean) и `User::makeActiveInternally()`: флаг становится `true` при наличии хотя бы одного `Channel::isVerified() === true`.
- Маршруты + контроллеры верификации: `ChannelVerificationAction` (`/user/channel/verification`), `SendChannelTokenAction`, `CreateChannelAction`. `ChannelVerificationAction` сам создаёт email-канал, если у юзера их ещё нет.
- `App\Shared\Application\Security\Voter\UserActiveVoter` с атрибутом `ACCESS_ACTIVE_USER`.
- `security.yaml`: `access_denied_url: /user/channel/verification` + правила `^/api/users/me`, `^/cabinet/*` → `ACCESS_ACTIVE_USER`. Эта схема покрывает только пути, для которых правило явно прописано; новые маршруты по умолчанию **не** защищены.

Что не нравится:

- Гейт размазан между `security.yaml` и Voter'ом.
- Default-allow: добавил новый маршрут — про защиту легко забыть.
- `^/cabinet` (без `*`) пускает inactive пользователя — баг конфигурации.

## Решения, зафиксированные на brainstorming

| Вопрос | Решение |
|---|---|
| Где гейтить | Один `EventSubscriber` на `KernelEvents::CONTROLLER` |
| Default-policy | Default-deny: блокируем всё, кроме явного whitelist'а |
| Что делать с `UserActiveVoter` | Удалить — заменён subscriber'ом |
| Что делать с `access_denied_url` | Удалить — redirect делает subscriber |
| Авто-отправка токена при signup | Нет, юзер сам жмёт «выслать» на verification-page |
| API behaviour для inactive | Тот же redirect (YAGNI; JSON 403 — позже, если понадобится) |
| Меню кабинета / edit-gating | Отдельная итерация |

## Архитектура

```
Request
  → Routing                       (определяет _route)
  → Firewall                      (сессия или JWT)
  → access_control                (IS_AUTHENTICATED где нужно)
  → KernelEvents::CONTROLLER
      → ChannelVerificationGate
          ├─ public route?               → pass
          ├─ anonymous?                  → pass (firewall ранее уже отрулил)
          ├─ user.isActive() === true    → pass
          └─ иначе → setController(redirect /user/channel/verification)
  → Controller
```

`KernelEvents::CONTROLLER` выбран потому, что:
- Срабатывает **после** firewall'а — `TokenStorage` уже содержит реального пользователя.
- Срабатывает **до** контроллера — можно подменить controller на функцию, возвращающую `RedirectResponse`.
- Для статики (`/build/*`, `/_wdt/*`) не выполняется — Symfony не выходит из роутера, событие не стреляет.

## Класс гейта

`app/src/Users/Application/Security/EventSubscriber/ChannelVerificationGate.php`:

```php
final class ChannelVerificationGate implements EventSubscriberInterface
{
    /**
     * Маршруты, на которые inactive пользователь обязан попадать,
     * иначе он будет в бесконечном редиректе или не сможет залогиниться.
     */
    private const PUBLIC_ROUTES = [
        'app_homepage',
        'app_login', 'app_logout', 'app_sign_up',
        'app_login_link', 'app_login_link_process',
        'app_user_channel_verification',
        'app_user_channel_send_token',
        'app_user_channel_create',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'onController'];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if ($route === null || in_array($route, self::PUBLIC_ROUTES, true)) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return; // анонимный — пусть firewall разруливает
        }
        if ($user->isActive()) {
            return;
        }

        $event->setController(fn () => new RedirectResponse(
            $this->urlGenerator->generate('app_user_channel_verification')
        ));
    }
}
```

Subscriber авто-регистрируется через `services.yaml` autoconfigure — никаких дополнительных конфигов не нужно.

## Изменения в репозитории

1. **Новый файл** `app/src/Users/Application/Security/EventSubscriber/ChannelVerificationGate.php` — содержимое выше.
2. **Удалить** `app/src/Shared/Application/Security/Voter/UserActiveVoter.php`. Параллельно по grep удалить любые упоминания константы `ACCESS_ACTIVE_USER` (security.yaml — см. ниже; контроллеры если есть; тесты).
3. **`config/packages/security.yaml`**:
   - Удалить строку `access_denied_url: /user/channel/verification`.
   - В `access_control` заменить блок:
     ```yaml
     - { path: ^/api/auth/token/login, roles: PUBLIC_ACCESS }
     - { path: ^/api/auth/token/refresh, roles: PUBLIC_ACCESS }
     - { path: ^/api/users/me,       roles: ACCESS_ACTIVE_USER}
     - { path: ^/cabinet, roles: IS_AUTHENTICATED }
     - { path: ^/cabinet/*, roles: ACCESS_ACTIVE_USER }
     - { path: ^/user/*, roles: IS_AUTHENTICATED }
     ```
     на:
     ```yaml
     - { path: ^/api/auth/token, roles: PUBLIC_ACCESS }
     - { path: ^/api,     roles: IS_AUTHENTICATED }
     - { path: ^/cabinet, roles: IS_AUTHENTICATED }
     - { path: ^/user,    roles: IS_AUTHENTICATED }
     ```
4. **Unit-тест** `app/tests/Unit/Users/Application/Security/EventSubscriber/ChannelVerificationGateTest.php` — три ветки (см. ниже).
5. **Functional-тест** `app/tests/Functional/Users/Infrastructure/Security/ChannelVerificationGateTest.php` — реальный HTTP-flow.

## Тесты

### Unit (`ChannelVerificationGateTest`)

Подкладываем мок `TokenStorageInterface` и `UrlGeneratorInterface`, конструируем `ControllerEvent` руками.

| Сценарий | Setup | Ожидание |
|---|---|---|
| Public route | request `_route` = `app_login` | `setController` не вызван |
| Anonymous | `tokenStorage->getToken()` возвращает null | `setController` не вызван |
| Inactive user, защищённый роут | inactive User в TokenStorage, `_route` = `app_cabinet_coating_coating_list` | `setController` вызван; контроллер возвращает `RedirectResponse` на `/user/channel/verification` |
| Active user | active User в TokenStorage | `setController` не вызван |
| Sub-request (ESI/forward) | `event->isMainRequest()` == false | `setController` не вызван |

### Functional (`ChannelVerificationGateTest`)

WebTestCase, реальная БД (паттерн из `tests/Functional/Coatings/.../CompareActionTest`).

| Сценарий | Действие | Ожидание |
|---|---|---|
| Anonymous → защищённый роут | GET `/cabinet/coating/coating/list` без login | 302 на `/login` (firewall, не subscriber) |
| Inactive → защищённый роут | login inactive юзера, GET `/cabinet/coating/coating/list` | 302 на `/user/channel/verification` |
| Inactive → verification page | login inactive юзера, GET `/user/channel/verification` | 200 (whitelist) |
| Active → защищённый роут | login active юзера, GET `/cabinet/coating/coating/list` | 200 |

Inactive юзер для теста: `User` без верифицированных каналов (флаг `isActive=false` по умолчанию). Active: вызвать `$user->makeActiveInternally()` после создания верифицированного канала.

## Что НЕ входит

- **Меню кабинета** (показать только «Покрытия» для не-админа). Отдельная итерация после merge этой.
- **Edit/delete gating** на странице покрытий. Уже работает через `canEdit = is_granted('ROLE_ADMIN')` — менять не нужно.
- **Авто-отправка токена** на email при signup.
- **Расширение `UserFactory`** (создание канала). Не нужно — `ChannelVerificationAction` сам создаёт email-канал при первом заходе.
- **JSON 403 для API**-маршрутов inactive юзера. Возвращаем тот же redirect — фронт API-only сейчас не используется.
- **Rate-limit** на отправку токена.
- **Каналы кроме email** (SMS, Telegram) — код их поддерживает, но это вне задачи.

## Открытые вопросы

- Нет.
