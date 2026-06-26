# Channel Verification Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Внедрить один глобальный гейт верификации канала: любой аутентифицированный пользователь с `User::isActive() === false` редиректится на `/user/channel/verification` при попытке зайти на любой защищённый маршрут.

**Architecture:** Один `EventSubscriber` на `KernelEvents::CONTROLLER` (default-deny с whitelist'ом маршрутов) заменяет существующий `ACCESS_ACTIVE_USER`-voter и `access_denied_url`. Subscriber авто-регистрируется через Symfony autoconfigure.

**Tech Stack:** PHP 8.3, Symfony 7 (HttpKernel, Security, Routing), PHPUnit 9.6, Doctrine ORM, Twig 3.

## Global Constraints

- Все команды запускаются из `app/` (рабочая директория проекта PHP/Symfony). `cd app` один раз в начале сессии.
- Юнит-тесты: `vendor/bin/phpunit tests/Unit/<path>`. Функциональные: `vendor/bin/phpunit tests/Functional/<path>`.
- Asset rebuild **не нужен** — задача чисто бэкендная.
- DDD-правила CLAUDE.md: бизнес-инварианты в домене (User уже имеет `isActive()`), Infrastructure только оркеструет.
- Whitelist маршрутов в subscriber'е использует **имена роутов** (`_route` атрибут), не пути — устойчивее к рефакторингу URL.
- Subscriber слушает `KernelEvents::CONTROLLER` (не `REQUEST`) — на этот момент firewall уже выполнил аутентификацию.
- Любые упоминания `ACCESS_ACTIVE_USER` в проекте (`security.yaml`, контроллеры, тесты) должны быть удалены вместе с воутером.
- `access_denied_url: /user/channel/verification` в `security.yaml` удаляется — редирект делает subscriber.
- Commit-checkpoints оставлены как комментарии для пользователя; план НЕ запускает `git add`/`git commit` сам. (Исключение — SDD-режим, где subagent'ы коммитят по правилу `feedback-sdd-commits`.)

---

### Task 1: ChannelVerificationGate subscriber (TDD)

**Files:**
- Create: `app/src/Users/Application/Security/EventSubscriber/ChannelVerificationGate.php`
- Test: `app/tests/Unit/Users/Application/Security/EventSubscriber/ChannelVerificationGateTest.php`

**Interfaces:**
- Consumes:
  - `Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface` (auto-wired).
  - `Symfony\Component\Routing\Generator\UrlGeneratorInterface` (auto-wired).
- Produces:
  - Класс `App\Users\Application\Security\EventSubscriber\ChannelVerificationGate`, реализующий `Symfony\Component\EventDispatcher\EventSubscriberInterface`.
  - Public method `onController(ControllerEvent $event): void`.
  - Public static method `getSubscribedEvents(): array` возвращает `[KernelEvents::CONTROLLER => 'onController']`.
  - Private const `PUBLIC_ROUTES`: точный массив имён роутов (см. ниже).

- [ ] **Step 1: Подготовить директории**

```bash
mkdir -p src/Users/Application/Security/EventSubscriber
mkdir -p tests/Unit/Users/Application/Security/EventSubscriber
```

Expected: команды отрабатывают без ошибки (директории создаются, либо уже существуют).

- [ ] **Step 2: Написать падающий unit-тест**

Создать `tests/Unit/Users/Application/Security/EventSubscriber/ChannelVerificationGateTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Users\Application\Security\EventSubscriber;

use App\Users\Application\Security\EventSubscriber\ChannelVerificationGate;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

final class ChannelVerificationGateTest extends TestCase
{
    private const VERIFICATION_URL = '/user/channel/verification';

    public function testSkipsWhenSubRequest(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects($this->never())->method('getToken');

        $gate = new ChannelVerificationGate($tokenStorage, $this->urlGenerator());

        $event = $this->controllerEvent('app_cabinet_coating_coating_list', HttpKernelInterface::SUB_REQUEST);
        $gate->onController($event);

        self::assertSame([$this, 'noopController'], $event->getController()); // controller не подменён
    }

    public function testSkipsWhenPublicRoute(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects($this->never())->method('getToken');

        $gate = new ChannelVerificationGate($tokenStorage, $this->urlGenerator());

        $event = $this->controllerEvent('app_login');
        $gate->onController($event);

        self::assertSame([$this, 'noopController'], $event->getController());
    }

    public function testSkipsWhenRouteNull(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects($this->never())->method('getToken');

        $gate = new ChannelVerificationGate($tokenStorage, $this->urlGenerator());

        $event = $this->controllerEvent(null);
        $gate->onController($event);

        self::assertSame([$this, 'noopController'], $event->getController());
    }

    public function testSkipsWhenAnonymous(): void
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn(null);

        $gate = new ChannelVerificationGate($tokenStorage, $this->urlGenerator());

        $event = $this->controllerEvent('app_cabinet_coating_coating_list');
        $gate->onController($event);

        self::assertSame([$this, 'noopController'], $event->getController());
    }

    public function testSkipsWhenUserActive(): void
    {
        $user = new User(new Email('active@example.com'));
        $this->forceIsActive($user, true);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $gate = new ChannelVerificationGate($tokenStorage, $this->urlGenerator());

        $event = $this->controllerEvent('app_cabinet_coating_coating_list');
        $gate->onController($event);

        self::assertSame([$this, 'noopController'], $event->getController());
    }

    public function testRedirectsWhenUserInactive(): void
    {
        $user = new User(new Email('inactive@example.com'));
        // isActive по умолчанию false — менять ничего не нужно.

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);

        $gate = new ChannelVerificationGate($tokenStorage, $this->urlGenerator());

        $event = $this->controllerEvent('app_cabinet_coating_coating_list');
        $gate->onController($event);

        $controller = $event->getController();
        self::assertIsCallable($controller);
        /** @var Response $response */
        $response = $controller();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(self::VERIFICATION_URL, $response->getTargetUrl());
    }

    // --- helpers ---

    public function noopController(): Response
    {
        return new Response('noop');
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')
            ->with('app_user_channel_verification')
            ->willReturn(self::VERIFICATION_URL);
        return $urlGenerator;
    }

    private function controllerEvent(?string $route, int $requestType = HttpKernelInterface::MAIN_REQUEST): ControllerEvent
    {
        $request = new Request();
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }
        $kernel = $this->createMock(HttpKernelInterface::class);
        return new ControllerEvent($kernel, [$this, 'noopController'], $request, $requestType);
    }

    private function forceIsActive(User $user, bool $value): void
    {
        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, $value);
    }
}
```

- [ ] **Step 3: Запустить тест, убедиться что падает**

```bash
vendor/bin/phpunit tests/Unit/Users/Application/Security/EventSubscriber/ChannelVerificationGateTest.php
```

Expected: ERROR — `Class "App\Users\Application\Security\EventSubscriber\ChannelVerificationGate" not found`.

- [ ] **Step 4: Создать класс**

Создать `app/src/Users/Application/Security/EventSubscriber/ChannelVerificationGate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Users\Application\Security\EventSubscriber;

use App\Users\Domain\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Глобальный гейт: пускает inactive (т.е. без верифицированных каналов) пользователя
 * только на whitelist-маршруты; на любые другие — редиректит на страницу верификации.
 *
 * Слушает CONTROLLER, потому что:
 *  - firewall уже выполнил аутентификацию (TokenStorage заполнен реальным юзером),
 *  - роутер уже определил _route (можно матчить по имени, не по path),
 *  - до вызова контроллера мы успеваем подменить его на возврат RedirectResponse.
 */
final class ChannelVerificationGate implements EventSubscriberInterface
{
    /**
     * Маршруты, доступные inactive юзеру. Любой маршрут вне этого списка для inactive
     * закончится редиректом на верификацию. Исключения:
     *  - login/logout/signup — без них юзер не сможет войти.
     *  - страница верификации и её сопутствующие экшены.
     *  - публичный homepage.
     *  - login-link flow (магические ссылки).
     */
    private const PUBLIC_ROUTES = [
        'app_homepage',
        'app_login',
        'app_logout',
        'app_sign_up',
        'app_login_link',
        'app_login_link_process',
        'app_user_channel_verification',
        'app_user_channel_send_token',
        'app_user_channel_create',
    ];

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

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
            return; // anonymous — пусть firewall разруливает
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

- [ ] **Step 5: Запустить тест, убедиться что зелёный**

```bash
vendor/bin/phpunit tests/Unit/Users/Application/Security/EventSubscriber/ChannelVerificationGateTest.php
```

Expected: `OK (6 tests, 6 assertions)` (число assertions может быть больше — каждый `self::assertSame` считается).

- [ ] **Step 6: Прогнать весь юнит-набор — регресс-чек**

```bash
vendor/bin/phpunit tests/Unit
```

Expected: все зелёные (включая 6 новых тестов).

- [ ] **Step 7: Commit checkpoint**

User commits 2 new files. Suggested message: `feat(users): add ChannelVerificationGate event subscriber`.

---

### Task 2: Удалить UserActiveVoter + упростить security.yaml

**Files:**
- Delete: `app/src/Shared/Application/Security/Voter/UserActiveVoter.php`
- Modify: `app/config/packages/security.yaml`

**Interfaces:**
- Consumes: ChannelVerificationGate из Task 1 (уже зарегистрирован через autoconfigure — никаких сервис-конфигов делать не нужно).
- Produces: упрощённый `access_control` без `ACCESS_ACTIVE_USER` и без `access_denied_url`.

- [ ] **Step 1: Проверить, что других потребителей ACCESS_ACTIVE_USER нет**

```bash
grep -rn "ACCESS_ACTIVE_USER" src config tests
```

Expected: ровно 3 строки — две в `src/Shared/Application/Security/Voter/UserActiveVoter.php` (определение константы + использование в `supports()`), одна-две в `config/packages/security.yaml` (правила access_control). Если найдутся прочие — STOP и эскалируй (план не покрывает их).

- [ ] **Step 2: Удалить файл воутера**

```bash
git rm src/Shared/Application/Security/Voter/UserActiveVoter.php
```

Expected: файл удалён, stage'нут как deletion.

- [ ] **Step 3: Обновить `config/packages/security.yaml`**

Открыть `app/config/packages/security.yaml`. Найти блок:

```yaml
  access_denied_url: /user/channel/verification
  access_control:
    - { path: ^/api/auth/token/login, roles: PUBLIC_ACCESS }
    - { path: ^/api/auth/token/refresh, roles: PUBLIC_ACCESS }
    - { path: ^/api/users/me,       roles: ACCESS_ACTIVE_USER}
    - { path: ^/cabinet, roles: IS_AUTHENTICATED }
    - { path: ^/cabinet/*, roles: ACCESS_ACTIVE_USER }
    - { path: ^/user/*, roles: IS_AUTHENTICATED }
```

Заменить на:

```yaml
  access_control:
    - { path: ^/api/auth/token, roles: PUBLIC_ACCESS }
    - { path: ^/api,     roles: IS_AUTHENTICATED }
    - { path: ^/cabinet, roles: IS_AUTHENTICATED }
    - { path: ^/user,    roles: IS_AUTHENTICATED }
```

Изменения:
- Удалена строка `access_denied_url: /user/channel/verification` целиком.
- `^/api/auth/token/login` + `^/api/auth/token/refresh` объединены в `^/api/auth/token` (префикс покрывает обе).
- `^/api/users/me` (был `ACCESS_ACTIVE_USER`) убран — гейтит subscriber.
- `^/cabinet` (был отдельно) и `^/cabinet/*` (был `ACCESS_ACTIVE_USER`) объединены в один `^/cabinet` с `IS_AUTHENTICATED` — `ACCESS_ACTIVE_USER` больше не существует, гейтит subscriber.
- `^/user/*` стал `^/user` (без `*`) для консистентности.

- [ ] **Step 4: Sanity-grep — убедиться что ACCESS_ACTIVE_USER нигде нет**

```bash
grep -rn "ACCESS_ACTIVE_USER\|access_denied_url" src config tests
```

Expected: пустой вывод.

- [ ] **Step 5: Проверить, что Symfony компилит контейнер**

```bash
bin/console cache:clear --env=dev
bin/console debug:event-dispatcher kernel.controller | grep -i ChannelVerificationGate
```

Expected: `cache:clear` без ошибок; `debug:event-dispatcher` показывает строчку про `App\Users\Application\Security\EventSubscriber\ChannelVerificationGate::onController` среди слушателей `kernel.controller`.

- [ ] **Step 6: Прогнать весь юнит-набор — регресс**

```bash
vendor/bin/phpunit tests/Unit
```

Expected: все зелёные.

- [ ] **Step 7: Commit checkpoint**

User commits 2 changed files (1 deletion + 1 modify). Suggested message: `refactor(security): replace UserActiveVoter with ChannelVerificationGate`.

---

### Task 3: Functional-тест ChannelVerificationGate (end-to-end)

**Files:**
- Create: `app/tests/Functional/Users/Infrastructure/Security/ChannelVerificationGateTest.php`

**Interfaces:**
- Consumes: всё, что мы только что сделали — Task 1 + Task 2 (subscriber работает в реальном Symfony стеке через autowire).
- Produces: 4 теста, покрывающих все четыре сценария из спеки.

- [ ] **Step 1: Подготовить директорию**

```bash
mkdir -p tests/Functional/Users/Infrastructure/Security
```

- [ ] **Step 2: Написать функциональный тест**

Создать `app/tests/Functional/Users/Infrastructure/Security/ChannelVerificationGateTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Security;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end проверка ChannelVerificationGate в реальном Symfony стеке.
 * Используем существующий маршрут /cabinet/coating/coating/list как защищённый
 * (он требует IS_AUTHENTICATED, и subscriber делает остальное).
 */
final class ChannelVerificationGateTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $this->userEmail = 'gate_test_' . uniqid('', true) . '@example.com';
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->userEmail]);
            if ($user !== null) {
                $em->remove($user);
                $em->flush();
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, "tearDown cleanup error: " . $e->getMessage() . "\n");
        }
        parent::tearDown();
    }

    public function testAnonymousIsRedirectedToLoginByFirewall(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/list');

        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        self::assertStringContainsString('/login', (string) $location, 'Anonymous должен попасть на /login, а не на verification');
    }

    public function testInactiveUserIsRedirectedToVerification(): void
    {
        $user = $this->createUser(active: false);
        $this->client->loginUser($user);

        $this->client->request('GET', '/cabinet/coating/coating/list');

        self::assertResponseRedirects('/user/channel/verification');
    }

    public function testInactiveUserCanAccessVerificationPage(): void
    {
        $user = $this->createUser(active: false);
        $this->client->loginUser($user);

        $this->client->request('GET', '/user/channel/verification');

        self::assertResponseIsSuccessful();
    }

    public function testActiveUserCanAccessProtectedRoute(): void
    {
        $user = $this->createUser(active: true);
        $this->client->loginUser($user);

        $this->client->request('GET', '/cabinet/coating/coating/list');

        self::assertResponseIsSuccessful();
    }

    private function createUser(bool $active): User
    {
        $hasher = $this->client->getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);
        if ($active) {
            $ref = new \ReflectionProperty($user, 'isActive');
            $ref->setAccessible(true);
            $ref->setValue($user, true);
        }
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }
}
```

- [ ] **Step 3: Запустить функциональный тест**

```bash
vendor/bin/phpunit tests/Functional/Users/Infrastructure/Security/ChannelVerificationGateTest.php
```

Expected: `OK (4 tests, ≥4 assertions)`.

Если падает `testInactiveUserCanAccessVerificationPage` — самая вероятная причина: шаблон `user/channel/verify.html.twig` упирается в неинициализированную форму на свежем юзере (в `ChannelVerificationAction` есть `$form->createView()` после try-catch, и при исключении `$form` может быть undefined). Если так — это pre-existing bug в `ChannelVerificationAction`, эскалируй пользователю, не пытайся фиксить в рамках этого плана.

- [ ] **Step 4: Финальный регресс — весь набор тестов**

```bash
vendor/bin/phpunit
```

Expected: все зелёные. Старые пред-существующие фейлы (`tests/Functional/Users/Infrastructure/Controller/GetMeActionTest`, `GetUserActionTest` с `token`-проблемой — они известны и не относятся к этой задаче) могут остаться красными, но количество новых красных = 0.

- [ ] **Step 5: Commit checkpoint**

User commits 1 new file. Suggested message: `test(security): functional coverage for ChannelVerificationGate`.

---

## Done

После Task 3: глобальный гейт верификации работает. Любой новый защищённый маршрут автоматически за гейтом (default-deny), `UserActiveVoter` мёртв. Следующие итерации (вне этого плана): меню кабинета для не-админа, авто-отправка токена при signup.
