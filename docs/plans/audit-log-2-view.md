# Audit Log — Deploy 2 (View) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Просмотр журнала в вашем UI: вкладка «История» на карточке покрытия (git-log объекта) и админ-журнал по классу с фильтром по актору (git-log класса).

**Architecture:** Read-side поверх `audit_log` из Деплоя 1. Репозиторий отдаёт `AuditEntry` в порядке `seq DESC`. Query/QueryHandler по CQRS-конвенции. `AuditLogTransformer` превращает `AuditEntry` в view-DTO, резолвя подписи полей из **карты `TrackedClass.fields` через `AuditPolicy`** (не переводы, не строки лога). Тонкие per-action контроллеры + Twig, скопированный с ближайшего аналога.

**Tech Stack:** PHP 8.2+, Symfony 7.0 (Twig), Doctrine ORM 3.1, PostgreSQL, PHPUnit 9.

**Spec:** этот файл. Предусловие — выполнен `docs/plans/audit-log-1-capture.md` (таблица `audit_log`, `AuditEntry`, `AuditPolicy`, захват копит историю). Соседний: `docs/plans/audit-log-3-admin-config.md`.

## Global Constraints

- Тонкий Action: собирает фильтр/DTO и диспатчит; логика — в Query-хендлере (`feedback_thin_controller`).
- Состояние фильтра — в URL (`feedback_filter_state_in_url`).
- Стили не сочинять — копировать разметку из `templates/admin/coating/coating/` (`feedback_reuse_styles`, `feedback_soft_borderless_design`).
- Просмотр — всем авторизованным (мутаций нет); кнопки правки под `{% if canEdit %}` (`project_cabinet_access_model`).
- Хендлеры — `implements *HandlerInterface` (`project_command_handler_interface`).
- В Twig только разметка; CSS — в `app/assets/styles/`. После фронта — `cd app && yarn dev`, проверка в браузере; PHP-тесты фронт не трогают.

## Согласованные решения

- Подписи полей — из карты `TrackedClass.fields` (через `AuditPolicy::trackedFields`), на чтении. Нет ключа → показываем сам `path`.
- Актор в строке — id; `'system'` → «Система», ULID → сам id (резолв имени профиля — по желанию позже).
- Порядок — `seq DESC`.
- Реконструкция состояния на момент (`git checkout`) — вне объёма (нужны снапшоты).

## File Structure

- Domain: `AuditEntryRepositoryInterface.php`.
- Application (`app/src/Shared/Application/Audit/`): `AuditLogView.php`, `FieldChangeView.php`, `AuditLogTransformer.php`, `Query/GetEntityAuditLog/{Query,Handler}.php`, `Query/GetClassAuditLog/{Query,Handler}.php`.
- Infrastructure: `Repository/AuditEntryRepository.php`; modify `AuditEntry.orm.xml` (замапить `seq`).
- Controllers: `Coatings/.../Coating/HistoryAction.php`, `AuditJournalAction.php`.
- Templates: `admin/coating/coating/history.html.twig`, `audit_journal.html.twig`; CSS `assets/styles/components/audit-log.css`.

---

### Task 1: Read-репозиторий + маппинг seq

**Files:** Create `AuditEntryRepositoryInterface.php`, `AuditEntryRepository.php`; Modify `AuditEntry.orm.xml`, `AuditEntry.php`; Test `tests/Functional/Shared/Audit/AuditEntryRepositoryTest.php`.

**Interfaces:** `forEntity(string $entityClass, string $entityId, Pager $p): list<AuditEntry>`; `forClass(string $entityClass, ?string $actorId, Pager $p): list<AuditEntry>` (порядок `seq DESC`). `AuditEntry::seq(): ?int`.

- [ ] **Step 1: Замапить `seq` + геттер**

В `AuditEntry.orm.xml` внутри `<entity>`: `<field name="seq" type="bigint" column="seq" generated="INSERT"/>`.
В `AuditEntry.php`: `private ?int $seq = null;` и `public function seq(): ?int { return null === $this->seq ? null : (int) $this->seq; }`.

- [ ] **Step 2: Интерфейс + Doctrine-репозиторий**
```php
<?php
declare(strict_types=1);
namespace App\Shared\Domain\Audit;

use App\Shared\Domain\Repository\Pager;

interface AuditEntryRepositoryInterface
{
    /** @return list<AuditEntry> */
    public function forEntity(string $entityClass, string $entityId, Pager $pager): array;

    /** @return list<AuditEntry> */
    public function forClass(string $entityClass, ?string $actorId, Pager $pager): array;
}
```
```php
<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Repository;

use App\Shared\Domain\Audit\AuditEntry;
use App\Shared\Domain\Audit\AuditEntryRepositoryInterface;
use App\Shared\Domain\Repository\Pager;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AuditEntry> */
final class AuditEntryRepository extends ServiceEntityRepository implements AuditEntryRepositoryInterface
{
    public function __construct(ManagerRegistry $registry) { parent::__construct($registry, AuditEntry::class); }

    public function forEntity(string $entityClass, string $entityId, Pager $pager): array
    {
        return $this->base($pager)
            ->where('a.entityClass = :c')->setParameter('c', $entityClass)
            ->andWhere('a.entityId = :id')->setParameter('id', $entityId)
            ->getQuery()->getResult();
    }

    public function forClass(string $entityClass, ?string $actorId, Pager $pager): array
    {
        $qb = $this->base($pager)->where('a.entityClass = :c')->setParameter('c', $entityClass);
        if (null !== $actorId) {
            $qb->andWhere('a.actorId = :actor')->setParameter('actor', $actorId);
        }

        return $qb->getQuery()->getResult();
    }

    private function base(Pager $pager): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.seq', 'DESC')
            ->setFirstResult($pager->offset())
            ->setMaxResults($pager->limit());
    }
}
```
Примечание: сверить API `Pager` (`offset()`/`limit()`) с существующим использованием и поправить.

- [ ] **Step 3: Функциональный тест**
```php
<?php
declare(strict_types=1);
namespace App\Tests\Functional\Shared\Audit;

use App\Shared\Domain\Audit\{AuditAction, AuditEntry, AuditEntryRepositoryInterface, ChangeSet, FieldChange};
use App\Shared\Domain\Repository\Pager;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AuditEntryRepositoryTest extends KernelTestCase
{
    public function testForEntityNewestFirst(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repo = self::getContainer()->get(AuditEntryRepositoryInterface::class);
        $eid = 'c-'.substr(md5((string) mt_rand()), 0, 8);

        foreach (['A', 'B'] as $i => $t) {
            $em->persist(new AuditEntry(Uuid::uuid4()->toString(), 'App\\X', $eid, AuditAction::Updated,
                new ChangeSet(FieldChange::set('title', (string) $i, $t)), 'system', new \DateTimeImmutable()));
            $em->flush();
        }

        $rows = $repo->forEntity('App\\X', $eid, Pager::fromPage(1, 10));
        self::assertCount(2, $rows);
        self::assertSame('B', $rows[0]->changes()->all()[0]->new); // seq DESC → B первым
    }
}
```
Run (контейнер) → PASS.

- [ ] **Step 4: Commit** — `git commit -m "Аудит: read-репозиторий журнала по объекту и классу, порядок seq DESC"`

---

### Task 2: view-DTO + трансформер (подписи из конфига)

**Files:** Create `FieldChangeView.php`, `AuditLogView.php`, `AuditLogTransformer.php`; Test `tests/Unit/Shared/Application/Audit/AuditLogTransformerTest.php`.

**Interfaces:** `AuditLogTransformer::view(AuditEntry $e): AuditLogView`. `AuditLogView{AuditAction $action, string $actorId, string $actorLabel, \DateTimeImmutable $occurredAt, string $entityId, list<FieldChangeView> $changes}`. `FieldChangeView{ChangeOp $op, string $label, string $path, mixed $old, mixed $new}` (op: set→old→new, add→только new, remove→только old).

- [ ] **Step 1: DTO**
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit;

use App\Shared\Domain\Audit\ChangeOp;

final readonly class FieldChangeView
{
    public function __construct(
        public ChangeOp $op,
        public string $label,
        public string $path,
        public mixed $old,
        public mixed $new,
    ) {}
}
```
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit;

use App\Shared\Domain\Audit\AuditAction;

final readonly class AuditLogView
{
    /** @param list<FieldChangeView> $changes */
    public function __construct(
        public AuditAction $action,
        public string $actorId,
        public string $actorLabel,
        public \DateTimeImmutable $occurredAt,
        public string $entityId,
        public array $changes,
    ) {}
}
```

- [ ] **Step 2: Падающий тест**
```php
<?php
declare(strict_types=1);
namespace App\Tests\Unit\Shared\Application\Audit;

use App\Shared\Application\Audit\AuditLogTransformer;
use App\Shared\Domain\Audit\{AuditAction, AuditEntry, AuditPolicyInterface, ChangeSet, FieldChange};
use PHPUnit\Framework\TestCase;

final class AuditLogTransformerTest extends TestCase
{
    private function policy(array $map): AuditPolicyInterface
    {
        return new class($map) implements AuditPolicyInterface {
            public function __construct(private array $map) {}
            public function trackedFields(string $entityClass): array { return $this->map; }
            public function invalidate(string $entityClass): void {}
        };
    }

    public function testLabelFromConfigAndSystemActor(): void
    {
        $t = new AuditLogTransformer($this->policy(['title' => 'Название']));
        $v = $t->view(new AuditEntry('id', 'App\\X', 'c1', AuditAction::Updated,
            new ChangeSet(FieldChange::set('title', 'X', 'Y')), 'system', new \DateTimeImmutable()));
        self::assertSame('Система', $v->actorLabel);
        self::assertSame('Название', $v->changes[0]->label);
    }

    public function testNestedHeadLabelKeepsTailAndUlidActor(): void
    {
        $t = new AuditLogTransformer($this->policy(['minRecoatingInterval' => 'Дерево перекрытия']));
        $v = $t->view(new AuditEntry('id', 'App\\X', 'c1', AuditAction::Updated,
            new ChangeSet(FieldChange::set('minRecoatingInterval.default', [1], [2])), 'ulid-1', new \DateTimeImmutable()));
        self::assertSame('Дерево перекрытия · default', $v->changes[0]->label);
        self::assertSame('ulid-1', $v->actorLabel);
    }
}
```
Run → FAIL.

- [ ] **Step 3: Трансформер**
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit;

use App\Shared\Domain\Audit\AuditEntry;
use App\Shared\Domain\Audit\AuditPolicyInterface;
use App\Shared\Domain\Audit\FieldChange;
use App\Shared\Domain\Security\SystemUser;

/** AuditEntry → view-DTO. Подпись поля — из карты TrackedClass.fields; нет ключа → сам path. */
final class AuditLogTransformer
{
    public function __construct(private readonly AuditPolicyInterface $policy) {}

    public function view(AuditEntry $e): AuditLogView
    {
        $labels = $this->policy->trackedFields($e->entityClass());
        $changes = array_map(
            fn (FieldChange $c): FieldChangeView => new FieldChangeView($c->op, $this->label($labels, $c->path), $c->path, $c->old, $c->new),
            $e->changes()->all(),
        );

        return new AuditLogView(
            $e->action(),
            $e->actorId(),
            SystemUser::ID === $e->actorId() ? 'Система' : $e->actorId(),
            $e->occurredAt(),
            $e->entityId(),
            $changes,
        );
    }

    /** @param array<string, string> $labels */
    private function label(array $labels, string $path): string
    {
        $head = explode('.', $path)[0];
        $tail = substr($path, strlen($head) + 1);
        $headLabel = $labels[$head] ?? $head;

        return '' === $tail ? $headLabel : $headLabel.' · '.$tail;
    }
}
```
Run → PASS.

- [ ] **Step 4: Commit** — `git commit -m "Аудит: трансформер лога — подписи полей из конфига TrackedClass"`

---

### Task 3: Query/Handler + вкладка «История» покрытия

**Files:** Create `GetEntityAuditLog/{Query,Handler}.php`, `Coating/HistoryAction.php`, `admin/coating/coating/history.html.twig`, `assets/styles/components/audit-log.css`.

**Interfaces:** `GetEntityAuditLogQuery(string $entityClass, string $entityId, int $page=1)`; handler → `list<AuditLogView>`.

- [ ] **Step 1: Query + Handler**
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit\Query\GetEntityAuditLog;

final readonly class GetEntityAuditLogQuery
{
    public function __construct(public string $entityClass, public string $entityId, public int $page = 1) {}
}
```
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit\Query\GetEntityAuditLog;

use App\Shared\Application\Audit\AuditLogTransformer;
use App\Shared\Application\Audit\AuditLogView;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Audit\AuditEntryRepositoryInterface;
use App\Shared\Domain\Repository\Pager;

final class GetEntityAuditLogQueryHandler implements QueryHandlerInterface
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly AuditEntryRepositoryInterface $repo,
        private readonly AuditLogTransformer $transformer,
    ) {}

    /** @return list<AuditLogView> */
    public function __invoke(GetEntityAuditLogQuery $q): array
    {
        $entries = $this->repo->forEntity($q->entityClass, $q->entityId, Pager::fromPage($q->page, self::PER_PAGE));

        return array_map($this->transformer->view(...), $entries);
    }
}
```
Примечание: сверить `QueryHandlerInterface`/маппинг query→handler с существующими Coatings-хендлерами.

- [ ] **Step 2: Контроллер `HistoryAction`**
```php
<?php
declare(strict_types=1);
namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Application\UseCase\Query\GetCoating\GetCoatingQuery;
use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Shared\Application\Audit\Query\GetEntityAuditLog\GetEntityAuditLogQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating/{id}/history', name: 'app_cabinet_coating_coating_history')]
class HistoryAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus) {}

    public function __invoke(Request $request, string $id): Response
    {
        $coating = $this->queryBus->execute(new GetCoatingQuery($id));
        if (!$coating->coatingDTO) {
            return $this->redirectToRoute('app_cabinet_coating_coating_list');
        }

        $log = $this->queryBus->execute(new GetEntityAuditLogQuery(Coating::class, $id, max(1, (int) $request->query->get('page', 1))));

        return $this->render('admin/coating/coating/history.html.twig', ['coating' => $coating->coatingDTO, 'log' => $log]);
    }
}
```

- [ ] **Step 3: Шаблон (копия аналога)**

Скопировать ближайший детальный/list-шаблон из `templates/admin/coating/coating/`, сохранить обёртку/layout 1-в-1, тело:
```twig
{% block body %}
  <h1>История: {{ coating.title }}</h1>
  {% for entry in log %}
    <div class="audit-entry">
      <div class="audit-entry__meta">{{ entry.actorLabel }} · {{ entry.occurredAt|date('d.m.Y H:i') }} · {{ entry.action.value }}</div>
      {% for change in entry.changes %}
        <div class="audit-entry__field">
          <span class="audit-entry__label">{{ change.label }}:</span>
          {% if change.op.value == 'set' %}
            <span class="audit-entry__old">{{ change.old is iterable ? change.old|json_encode : change.old }}</span>
            →
            <span class="audit-entry__new">{{ change.new is iterable ? change.new|json_encode : change.new }}</span>
          {% elseif change.op.value == 'add' %}
            <span class="audit-entry__add">+ {{ change.new is iterable ? change.new|json_encode : change.new }}</span>
          {% else %}
            <span class="audit-entry__remove">− {{ change.old is iterable ? change.old|json_encode : change.old }}</span>
          {% endif %}
        </div>
      {% endfor %}
    </div>
  {% else %}
    <p>Изменений пока нет.</p>
  {% endfor %}
{% endblock %}
```
CSS `.audit-entry*` — в `assets/styles/components/audit-log.css` (оттенки/тени, без новых цветов), импорт в `app.css`. Ссылку «История» добавить на карточку/в список покрытий (просмотр всем, не под `canEdit`).

- [ ] **Step 4: Ассеты + браузер** — `cd app && yarn dev`; проверить `/cabinet/coating/coating/{id}/history`.

- [ ] **Step 5: Commit** — `git commit -m "Аудит: вкладка История покрытия — лента изменений с подписями из конфига"`

---

### Task 4: Админ-журнал по классу + фильтр по актору

**Files:** Create `GetClassAuditLog/{Query,Handler}.php`, `Coating/AuditJournalAction.php`, `admin/coating/coating/audit_journal.html.twig`.

**Interfaces:** `GetClassAuditLogQuery(string $entityClass, ?string $actorId=null, int $page=1)`; handler → `list<AuditLogView>`.

- [ ] **Step 1: Query + Handler**
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit\Query\GetClassAuditLog;

final readonly class GetClassAuditLogQuery
{
    public function __construct(public string $entityClass, public ?string $actorId = null, public int $page = 1) {}
}
```
```php
<?php
declare(strict_types=1);
namespace App\Shared\Application\Audit\Query\GetClassAuditLog;

use App\Shared\Application\Audit\AuditLogTransformer;
use App\Shared\Application\Audit\AuditLogView;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Audit\AuditEntryRepositoryInterface;
use App\Shared\Domain\Repository\Pager;

final class GetClassAuditLogQueryHandler implements QueryHandlerInterface
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly AuditEntryRepositoryInterface $repo,
        private readonly AuditLogTransformer $transformer,
    ) {}

    /** @return list<AuditLogView> */
    public function __invoke(GetClassAuditLogQuery $q): array
    {
        $entries = $this->repo->forClass($q->entityClass, $q->actorId, Pager::fromPage($q->page, self::PER_PAGE));

        return array_map($this->transformer->view(...), $entries);
    }
}
```

- [ ] **Step 2: Контроллер `AuditJournalAction`**
```php
<?php
declare(strict_types=1);
namespace App\Coatings\Infrastructure\Controller\Coating;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Shared\Application\Audit\Query\GetClassAuditLog\GetClassAuditLogQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/coating/coating/audit-journal', name: 'app_cabinet_coating_coating_audit_journal')]
class AuditJournalAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus) {}

    public function __invoke(Request $request): Response
    {
        $actorId = $request->query->get('actor') ?: null; // фильтр в URL
        $log = $this->queryBus->execute(new GetClassAuditLogQuery(Coating::class, $actorId, max(1, (int) $request->query->get('page', 1))));

        return $this->render('admin/coating/coating/audit_journal.html.twig', ['log' => $log, 'actorId' => $actorId]);
    }
}
```

- [ ] **Step 3: Шаблон (копия list-аналога)**

Скопировать list-шаблон покрытий, тело — строки записей (объект-ссылка на `app_cabinet_coating_coating_history` с `entry.entityId`, актор, действие, время, свёрнутые поля). Фильтр по актору — input в URL-параметре `actor`, состояние из query.

- [ ] **Step 4: Ассеты + браузер** — `cd app && yarn dev`; проверить `/cabinet/coating/coating/audit-journal` и `?actor=<id>`.

- [ ] **Step 5: Commit** — `git commit -m "Аудит: админ-журнал изменений покрытий с фильтром по актору"`

---

## Self-Review

- **Spec coverage:** per-object история (T3) · per-class журнал+фильтр (T4) · подписи из конфига (T2) · актор id→«Система»/ulid (T2) · порядок seq DESC (T1) · реконструкция вне объёма. Захват — Деплой 1; управление — Деплой 3.
- **Placeholder scan:** Twig опирается на «копию аналога» (обязательная конвенция) — каркас тела дан.
- **Type consistency:** `AuditEntryRepositoryInterface::forEntity/forClass → list<AuditEntry>` · `AuditLogTransformer::view(AuditEntry): AuditLogView` · handlers → `list<AuditLogView>` · `Pager::fromPage(page, per)` — сверить API.

## Заметки

- `generated="INSERT"` для `seq`: если ORM 3.1 не подхватит XML-атрибут — план Б: не мапить `seq`, читать через NativeQuery с `ORDER BY seq`, либо `ORDER BY occurred_at DESC, id DESC`.
- Имена `QueryHandlerInterface`/`QueryBusInterface`/`Pager` — из существующих Coatings-хендлеров.
