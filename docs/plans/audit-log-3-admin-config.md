# Audit Log — Deploy 3 (Admin Config: dynamic class/field picker) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Админ-интерфейс управления аудитом: выбрать любой Doctrine-класс из списка кандидатов, отметить его поля чекбоксами, задать подписи, сохранить — и класс начинает отслеживаться без деплоя.

**Architecture:** Read-модель из `AuditableRegistry` (кандидаты + поля из метаданных) + `TrackedClassRepository` (текущее состояние). Сохранение — Command/Handler: авторизация через `AuditAccessControl` (ROLE_ADMIN/система), валидация полей против метаданных, upsert/remove `TrackedClass`, инвалидация кэша `AuditPolicy`. Тонкий per-action контроллер + Twig (копия аналога). Пикер — не текст-путь, а выбор класса из списка и чекбоксы полей.

**Tech Stack:** PHP 8.2+, Symfony 7.0 (Twig, Security), Doctrine ORM 3.1, PHPUnit 9.

**Spec:** этот файл. Предусловие — выполнен Деплой 1 (`TrackedClass`, `TrackedClassRepositoryInterface`, `AuditPolicyInterface`, `AuditableRegistry`). Соседние: `audit-log-1-capture.md`, `audit-log-2-view.md`.

## Global Constraints

- Авторизация — в Application-хендлере через `*AccessControl`, не `#[IsGranted]` (CLAUDE.md «Авторизация и доступ»). Capability-форма: `canManage()` → `AccessGuard::isManager()`.
- Отказ — `App\Shared\Infrastructure\Exception\ForbiddenException`.
- Тонкий Action; логика в Command-хендлере. Кнопки под `{% if canEdit %}`.
- Валидация «поле существует у класса» — в хендлере (нужны метаданные = инфраструктура/приложение), не в домене. Домен `TrackedClass` держит лишь инвариант непустоты.
- Стили — копия аналога `templates/admin/coating/coating/`. В Twig только разметка; JS — Stimulus при необходимости. После фронта — `cd app && yarn dev`.
- Хендлеры — `implements CommandHandlerInterface`/`QueryHandlerInterface`.

## Согласованные решения

- Пикер: выбор класса из списка кандидатов (`AuditableRegistry::auditableClasses()`), НЕ ввод пути/FQCN руками. Поля — чекбоксы из `mappedFields()`; подпись — input рядом (по умолчанию имя поля).
- Пустой набор полей = снять класс с аудита (удалить строку `TrackedClass`).
- Кандидаты = mapped-сущности минус внутренние (`audit_log`, `audit_tracked_class`).
- Опционально: сам `TrackedClass` можно завести под аудит (мета-аудит «кто менял отслеживание») — отдельным шагом.

## File Structure

- Application (`app/src/Shared/Application/Audit/`):
  - `AuditAccessControl.php`
  - `Command/SaveTrackedClass/{SaveTrackedClassCommand,SaveTrackedClassCommandHandler}.php`
  - `Query/GetAuditConfig/{GetAuditConfigQuery,GetAuditConfigQueryHandler}.php`
  - `AuditConfigView.php`, `AuditClassView.php`, `AuditFieldView.php`
- Infrastructure: `Controller/Audit/AuditConfigAction.php`
- Templates: `admin/audit/config.html.twig`
- Config (при необходимости): маршрутизация для `App\Shared\Infrastructure\Controller\`.

---

### Task 1: Доступ + сохранение конфига (валидация + upsert/remove + инвалидация)

**Files:** Create `AuditAccessControl.php`, `SaveTrackedClassCommand.php`, `SaveTrackedClassCommandHandler.php`; Test `tests/Functional/Shared/Audit/SaveTrackedClassHandlerTest.php`.

**Interfaces:** `SaveTrackedClassCommand(string $entityClass, array $fields)` где `$fields: array<string,string>` (поле→подпись; пустой = снять с аудита). Handler `implements CommandHandlerInterface`.

- [ ] **Step 1: AccessControl**
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit;

use App\Shared\Application\Security\AccessGuard;

final class AuditAccessControl
{
    public function __construct(private readonly AccessGuard $guard) {}

    public function canManage(): bool { return $this->guard->isManager(); }
}
```
Примечание: сверить неймспейс/метод `AccessGuard::isManager()` с существующим (см. `DocumentAccessControl`/`CoatingAccessControl`).

- [ ] **Step 2: Command**
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit\Command\SaveTrackedClass;

final readonly class SaveTrackedClassCommand
{
    /** @param array<string, string> $fields поле → подпись (пусто = снять класс с аудита) */
    public function __construct(public string $entityClass, public array $fields) {}
}
```

- [ ] **Step 3: Падающий функциональный тест**
```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Shared\Audit;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Shared\Application\Audit\Command\SaveTrackedClass\SaveTrackedClassCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Audit\AuditPolicyInterface;
use App\Shared\Domain\Audit\TrackedClassRepositoryInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SaveTrackedClassHandlerTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    public function testSaveThenUntrack(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $bus = self::getContainer()->get(CommandBusInterface::class);
        $repo = self::getContainer()->get(TrackedClassRepositoryInterface::class);
        $policy = self::getContainer()->get(AuditPolicyInterface::class);

        $bus->execute(new SaveTrackedClassCommand(Coating::class, ['title' => 'Название']));
        $policy->invalidate(Coating::class);
        self::assertSame(['title' => 'Название'], $repo->findByClass(Coating::class)?->fields());

        $bus->execute(new SaveTrackedClassCommand(Coating::class, [])); // снять с аудита
        self::assertNull($repo->findByClass(Coating::class));
    }

    public function testRejectsUnknownField(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $this->expectException(AppException::class);
        self::getContainer()->get(CommandBusInterface::class)
            ->execute(new SaveTrackedClassCommand(Coating::class, ['nope_not_a_field' => 'X']));
    }
}
```
Run (контейнер) → FAIL.

- [ ] **Step 4: Handler**
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit\Command\SaveTrackedClass;

use App\Shared\Application\Audit\AuditAccessControl;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Audit\AuditPolicyInterface;
use App\Shared\Domain\Audit\TrackedClass;
use App\Shared\Domain\Audit\TrackedClassRepositoryInterface;
use App\Shared\Infrastructure\Audit\AuditableRegistry;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Ramsey\Uuid\Uuid;

final class SaveTrackedClassCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly TrackedClassRepositoryInterface $repo,
        private readonly AuditableRegistry $registry,
        private readonly AuditPolicyInterface $policy,
        private readonly AuditAccessControl $access,
    ) {}

    public function __invoke(SaveTrackedClassCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }
        if (!in_array($command->entityClass, $this->registry->auditableClasses(), true)) {
            throw new AppException('Класс недоступен для аудита.');
        }
        $mapped = $this->registry->mappedFields($command->entityClass);
        foreach (array_keys($command->fields) as $field) {
            if (!in_array($field, $mapped, true)) {
                throw new AppException(sprintf('Поле "%s" не существует у класса.', $field));
            }
        }

        $existing = $this->repo->findByClass($command->entityClass);
        if ([] === $command->fields) {
            if (null !== $existing) {
                $this->repo->remove($existing); // снять класс с аудита
            }
        } elseif (null !== $existing) {
            $existing->retrack($command->fields);
            $this->repo->save($existing);
        } else {
            $this->repo->save(new TrackedClass(Uuid::uuid4()->toString(), $command->entityClass, $command->fields));
        }

        $this->policy->invalidate($command->entityClass);
    }
}
```
Run → PASS.

- [ ] **Step 5: Commit** — `git commit -m "Аудит: сохранение конфига класса — валидация полей, upsert/снятие, инвалидация кэша"`

---

### Task 2: Read-модель конфига для UI

**Files:** Create `AuditFieldView.php`, `AuditClassView.php`, `AuditConfigView.php`, `GetAuditConfigQuery.php`, `GetAuditConfigQueryHandler.php`; Test `tests/Functional/Shared/Audit/GetAuditConfigHandlerTest.php`.

**Interfaces:** `GetAuditConfigQuery()`; handler → `AuditConfigView{ list<AuditClassView> classes }`. `AuditClassView{ string $entityClass, string $classLabel, list<AuditFieldView> fields }`. `AuditFieldView{ string $name, string $label, bool $tracked }`.

- [ ] **Step 1: View-DTO**
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit;

final readonly class AuditFieldView
{
    public function __construct(public string $name, public string $label, public bool $tracked) {}
}
```
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit;

final readonly class AuditClassView
{
    /** @param list<AuditFieldView> $fields */
    public function __construct(public string $entityClass, public string $classLabel, public array $fields) {}
}
```
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit;

final readonly class AuditConfigView
{
    /** @param list<AuditClassView> $classes */
    public function __construct(public array $classes) {}
}
```

- [ ] **Step 2: Query + Handler**
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit\Query\GetAuditConfig;

final readonly class GetAuditConfigQuery {}
```
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit\Query\GetAuditConfig;

use App\Shared\Application\Audit\AuditClassView;
use App\Shared\Application\Audit\AuditConfigView;
use App\Shared\Application\Audit\AuditFieldView;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Audit\TrackedClassRepositoryInterface;
use App\Shared\Infrastructure\Audit\AuditableRegistry;

final class GetAuditConfigQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly AuditableRegistry $registry,
        private readonly TrackedClassRepositoryInterface $repo,
    ) {}

    public function __invoke(GetAuditConfigQuery $query): AuditConfigView
    {
        $classes = [];
        foreach ($this->registry->auditableClasses() as $class) {
            $current = $this->repo->findByClass($class)?->fields() ?? []; // поле → подпись
            $fields = array_map(
                static fn (string $name): AuditFieldView => new AuditFieldView(
                    $name,
                    $current[$name] ?? $name,
                    array_key_exists($name, $current),
                ),
                $this->registry->mappedFields($class),
            );
            $short = substr((string) strrchr('\\'.$class, '\\'), 1);
            $classes[] = new AuditClassView($class, $short, $fields);
        }

        return new AuditConfigView($classes);
    }
}
```

- [ ] **Step 3: Функциональный тест**
```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Shared\Audit;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Shared\Application\Audit\Query\GetAuditConfig\GetAuditConfigQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetAuditConfigHandlerTest extends KernelTestCase
{
    public function testListsCoatingWithTrackedFlags(): void
    {
        self::bootKernel();
        $view = self::getContainer()->get(QueryBusInterface::class)->execute(new GetAuditConfigQuery());

        $coating = null;
        foreach ($view->classes as $c) {
            if (Coating::class === $c->entityClass) { $coating = $c; break; }
        }
        self::assertNotNull($coating);
        self::assertSame('Coating', $coating->classLabel);
        $title = null;
        foreach ($coating->fields as $f) {
            if ('title' === $f->name) { $title = $f; break; }
        }
        self::assertNotNull($title);
        self::assertTrue($title->tracked); // засеяно в Деплое 1
    }
}
```
Run (контейнер) → PASS.

- [ ] **Step 4: Commit** — `git commit -m "Аудит: read-модель конфига — классы и поля из метаданных с текущими флагами"`

---

### Task 3: Контроллер-пикер + шаблон

**Files:** Create `Controller/Audit/AuditConfigAction.php`, `templates/admin/audit/config.html.twig`; при необходимости — маршрутизация для `App\Shared\Infrastructure\Controller\`.

**Interfaces:** GET рендерит `AuditConfigView`; POST собирает `entityClass` + отмеченные поля + подписи → `SaveTrackedClassCommand`.

- [ ] **Step 1: Контроллер**
```php
<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Controller\Audit;

use App\Shared\Application\Audit\Command\SaveTrackedClass\SaveTrackedClassCommand;
use App\Shared\Application\Audit\Query\GetAuditConfig\GetAuditConfigQuery;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/audit/config', name: 'app_cabinet_audit_config')]
class AuditConfigAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
    ) {}

    public function __invoke(Request $request): Response
    {
        if ($request->isMethod(Request::METHOD_POST)) {
            try {
                $entityClass = (string) $request->getPayload()->get('entityClass');
                $checked = (array) $request->getPayload()->all('fields');   // list имён отмеченных полей
                $labels = (array) $request->getPayload()->all('labels');    // имя → подпись

                $map = [];
                foreach ($checked as $name) {
                    $name = (string) $name;
                    $label = (string) ($labels[$name] ?? '');
                    $map[$name] = '' === $label ? $name : $label;
                }

                $this->commandBus->execute(new SaveTrackedClassCommand($entityClass, $map));
                $this->addFlash('audit_config_success', 'Настройки аудита сохранены.');
            } catch (\Exception $e) {
                $this->addFlash('audit_config_error', $e->getMessage());
            }

            return $this->redirectToRoute('app_cabinet_audit_config');
        }

        return $this->render('admin/audit/config.html.twig', [
            'config' => $this->queryBus->execute(new GetAuditConfigQuery()),
        ]);
    }
}
```
Примечание: если `App\Shared\Infrastructure\Controller\` не сканируется маршрутизацией — добавить ресурс в `config/routes.yaml` (attribute-роуты для этого неймспейса), по образцу регистрации контроллеров контекстов.

- [ ] **Step 2: Шаблон (копия аналога)**

Скопировать обёртку из `templates/admin/coating/coating/`, тело — по классу форма с чекбоксами и инпутами подписей:
```twig
{% block body %}
  <h1>Аудит: отслеживаемые поля</h1>
  {% for class in config.classes %}
    <form method="post" class="audit-config__class">
      <h2>{{ class.classLabel }}</h2>
      <input type="hidden" name="entityClass" value="{{ class.entityClass }}">
      {% for field in class.fields %}
        <div class="audit-config__field">
          <label>
            <input type="checkbox" name="fields[]" value="{{ field.name }}" {{ field.tracked ? 'checked' : '' }}>
            {{ field.name }}
          </label>
          <input type="text" name="labels[{{ field.name }}]" value="{{ field.label }}" placeholder="{{ field.name }}">
        </div>
      {% endfor %}
      <button type="submit" class="btn-soft-success">Сохранить</button>
    </form>
  {% endfor %}
{% endblock %}
```
Кнопка `.btn-soft-success` — как в проекте (`feedback_soft_borderless_design`). Стили `.audit-config*` — в `assets/styles/admin/audit-config.css`, импорт в `app.css`. Список классов длинный → при желании обернуть в аккордеон/поиск (Stimulus), но MVP — простой список.

- [ ] **Step 3: Ассеты + браузер** — `cd app && yarn dev`; на `/cabinet/audit/config` под ROLE_ADMIN: отметить поле Document, задать подпись, сохранить, убедиться что запись `audit_tracked_class` появилась и правки Document начали писаться в лог.

- [ ] **Step 4: Проверка доступа** — не-админ на POST упирается в `ForbiddenException` из хендлера (мутация заблокирована).

- [ ] **Step 5: Commit** — `git commit -m "Аудит: админ-пикер — выбор класса и полей из метаданных, сохранение в конфиг"`

---

### Task 4 (опционально): мета-аудит конфига

Чтобы «кто менял отслеживание» попадало в общий лог, завести строку `TrackedClass` для самого `TrackedClass` (или для audit-config-операций). Проще всего — сид: включить аудит полей `entityClass`,`fields` у `App\Shared\Domain\Audit\TrackedClass`. Тогда любое сохранение/снятие фиксируется тем же слушателем (рекурсии нет — `audit_log` не аудируется). Валить в отдельную миграцию-сид, если решите включать.

- [ ] **Step 1:** Миграция-сид `INSERT ... audit_tracked_class` для `App\Shared\Domain\Audit\TrackedClass` с полями `{"entityClass":"Класс","fields":"Поля"}` `ON CONFLICT DO NOTHING`. Но учесть: `TrackedClass` в `AuditableRegistry::INTERNAL` (исключён из кандидатов UI) — для мета-аудита либо убрать из INTERNAL, либо разрешить сид напрямую в БД. Решение — за пользователем; по умолчанию НЕ включаем (меньше самореференса).

- [ ] **Step 2: Commit** (если делаем) — `git commit -m "Аудит: мета-аудит конфига — изменения отслеживания попадают в общий лог"`

---

## Self-Review

- **Spec coverage:** выбор класса из кандидатов (T2, T3) · чекбоксы полей из метаданных + подписи (T2, T3) · сохранение с валидацией против метаданных (T1) · снятие класса пустым набором (T1) · инвалидация кэша (T1) · авторизация ROLE_ADMIN через AccessControl (T1) · мета-аудит опционально (T4).
- **Placeholder scan:** Twig — копия аналога (конвенция), каркас дан; T4 явно опциональна.
- **Type consistency:** `SaveTrackedClassCommand(entityClass, fields:map)` · `AuditableRegistry::auditableClasses()/mappedFields()` · `TrackedClass::retrack(map)` · `AuditConfigView{classes}`/`AuditClassView{entityClass,classLabel,fields}`/`AuditFieldView{name,label,tracked}` — согласованы T1-3 и с Деплоем 1.

## Заметки

- `AccessGuard::isManager()` / `ForbiddenException` / `CommandHandlerInterface` — сверить с существующими (`DocumentAccessControl`, любой Command-хендлер).
- Маршрутизация Shared-контроллеров: проверить, что `/cabinet/audit/config` резолвится (может потребоваться ресурс в `routes.yaml`).
- Кандидатов может быть много (все сущности) — для UX позже добавить поиск/аккордеон; на работу не влияет.
