# File Storage Backbone (Деплой 1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Единая подсистема хранения файлов в `Shared` — порт `FileStorage`, центральный реестр `stored_file`, реализация на Flysystem, двухфазная загрузка (stage→promote), генерик tmp-upload endpoint и чистка сирот. Потребителей ещё нет (перенос Certificates — Деплой 2).

**Architecture:** Порт `FileStorage` (Domain) + реализация `FlysystemFileStorage` (Infrastructure) поверх уже подключённого `oneup/flysystem-bundle`. Метаданные — один мутируемый Doctrine-entity `StoredFile` (append + промоушен), доступ через `StoredFileRepository`. Назначение файла — контракт `FilePurpose` (enum контекста реализует его в Деплое 2+). Всё IO через `FilesystemOperator` — переезд на S3 остаётся сменой адаптера.

**Tech Stack:** PHP 8, Symfony 7, Doctrine ORM (XML-маппинг), `league/flysystem` + `oneup/flysystem-bundle` (адаптер `local`), `symfony/uid`, PHPUnit.

**Spec:** `docs/plans/file-storage-shared-design.md` (зонтичный дизайн; этот план реализует §11 План 1).

## Global Constraints

- **Хранилище-агностик:** любое файловое IO — только через `League\Flysystem\FilesystemOperator`. Запрещены `fopen`/`file_get_contents`/`move_uploaded_file` по вычисленному локальному пути. Единственное исключение — `toLocalTempFile()`, который материализует копию стримом во временный каталог.
- **Ошибки пользователю** — `App\Shared\Infrastructure\Exception\AppException` (код по умолчанию 422; 404 для not-found через второй аргумент). Русское сообщение.
- **Валидация** — встроенные проверки/`getimagesize`, без кастомных валидатор-классов (правило проекта [[feedback_less_code]]).
- **MIME — по содержимому** (`UploadedFile::getMimeType()`), не по заголовку клиента.
- **Тесты зеркалят `src/`.** Unit — на хосте; **functional требуют БД → гонять в контейнере `manager_php-fpm` с override `DATABASE_URL@manager_db`** ([[reference_test_run_env]]). Финальный гейт — `./run check` (style/phpstan), не только phpunit ([[feedback_sdd_run_check_gates]]).
- **Коммиты** — одна строка, ≤150 символов, без эмоджи, без ID задачи (инфраструктура). SDD-имплементер коммитит по задаче ([[feedback_sdd_commits]]).
- **Рефайнменты спеки (подтверждены пользователем):** `StoredFile` — единый мутируемый mapped-entity (не VO+Record, без `StorageKeyFactory`); размер — колонка `INTEGER`.

---

## Структура файлов

Создаётся:
- `src/Shared/Domain/File/FileConstraints.php` — VO лимитов.
- `src/Shared/Domain/File/FilePurpose.php` — контракт назначения.
- `src/Shared/Domain/File/StoredFile.php` — mapped-entity реестра (identity + метаданные + форма пути).
- `src/Shared/Domain/File/FileStorage.php` — порт.
- `src/Shared/Domain/File/StoredFileRepositoryInterface.php` — контракт доступа к реестру.
- `src/Shared/Infrastructure/File/FlysystemFileStorage.php` — реализация порта.
- `src/Shared/Infrastructure/Repository/StoredFileRepository.php` — Doctrine-реализация реестра.
- `src/Shared/Infrastructure/Database/ORM/File/StoredFile.orm.xml` — маппинг.
- `src/Shared/Infrastructure/Database/Migrations/Version20260919090000.php` — таблица `stored_file`.
- `src/Shared/Application/File/StageFiles/{StageFilesCommand,StageFilesCommandHandler,StageFilesCommandResult}.php` + `src/Shared/Application/File/StagedFileView.php` — слайс генерик-загрузки.
- `src/Shared/Infrastructure/Controller/File/UploadStagedAction.php` — genrик endpoint.
- `src/Shared/Infrastructure/Controller/Console/PurgeExpiredFilesCommand.php` — чистка сирот.
- Тест-фикстура `tests/Support/File/FakePurpose.php` + тесты (зеркало).

Модифицируется:
- `config/packages/oneup_flysystem.yaml` — адаптер+filesystem `file_storage`.
- `config/services.yaml` — параметр каталога, биндинг filesystem, алиасы порта и репозитория.
- `config/packages/doctrine.yaml` — mapping-секция `SharedFile`.

---

### Task 1: `FileConstraints` VO

**Files:**
- Create: `src/Shared/Domain/File/FileConstraints.php`
- Test: `tests/Unit/Shared/File/FileConstraintsTest.php`

**Interfaces:**
- Produces: `final readonly FileConstraints` с `__construct(int $maxBytes, array $mimeTypes, ?int $maxWidth = null, ?int $maxHeight = null)`; геттеры `maxBytes():int`, `mimeTypes():array`, `maxWidth():?int`, `maxHeight():?int`. Инвариант: `maxBytes>0` и непустой `mimeTypes`, иначе `AppException`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\File;

use App\Shared\Domain\File\FileConstraints;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class FileConstraintsTest extends TestCase
{
    public function testExposesLimits(): void
    {
        $c = new FileConstraints(1024, ['image/png'], 800, 600);
        self::assertSame(1024, $c->maxBytes());
        self::assertSame(['image/png'], $c->mimeTypes());
        self::assertSame(800, $c->maxWidth());
        self::assertSame(600, $c->maxHeight());
    }

    public function testRejectsNonPositiveMaxBytes(): void
    {
        $this->expectException(AppException::class);
        new FileConstraints(0, ['image/png']);
    }

    public function testRejectsEmptyMimeList(): void
    {
        $this->expectException(AppException::class);
        new FileConstraints(1024, []);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Shared/File/FileConstraintsTest.php`
Expected: FAIL — class `FileConstraints` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\File;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class FileConstraints
{
    /**
     * @param list<string> $mimeTypes
     */
    public function __construct(
        private int $maxBytes,
        private array $mimeTypes,
        private ?int $maxWidth = null,
        private ?int $maxHeight = null,
    ) {
        if ($maxBytes <= 0) {
            throw new AppException('Лимит размера файла должен быть положительным.');
        }
        if ([] === $mimeTypes) {
            throw new AppException('Список допустимых типов файла не может быть пустым.');
        }
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    /** @return list<string> */
    public function mimeTypes(): array
    {
        return $this->mimeTypes;
    }

    public function maxWidth(): ?int
    {
        return $this->maxWidth;
    }

    public function maxHeight(): ?int
    {
        return $this->maxHeight;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd app && vendor/bin/phpunit tests/Unit/Shared/File/FileConstraintsTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Shared/Domain/File/FileConstraints.php tests/Unit/Shared/File/FileConstraintsTest.php
git commit -m "Лимиты файла оформлены как VO FileConstraints с инвариантами размера и mime"
```

---

### Task 2: `FilePurpose` контракт + `StoredFile` entity

**Files:**
- Create: `src/Shared/Domain/File/FilePurpose.php`
- Create: `src/Shared/Domain/File/StoredFile.php`
- Create: `tests/Support/File/FakePurpose.php`
- Test: `tests/Unit/Shared/File/StoredFileTest.php`

**Interfaces:**
- Consumes: `FileConstraints` (Task 1).
- Produces:
  - `interface FilePurpose { public function storagePrefix(): string; public function key(): string; public function constraints(): FileConstraints; }`
  - `final class StoredFile` с константами `STATUS_STAGED='staged'`, `STATUS_STORED='stored'`; статик-фабрики `staged(string $id, string $uploaderId, string $originalName, string $mime, string $extension, int $size, \DateTimeImmutable $createdAt, \DateTimeImmutable $expiresAt): self` и `stored(string $id, FilePurpose $purpose, string $ownerId, string $originalName, string $mime, string $extension, int $size, \DateTimeImmutable $createdAt): self`; метод `promote(FilePurpose $purpose, string $ownerId): void`; геттеры `id/status/purpose/ownerId/uploaderId/originalName/mime/extension/size/storageKey/createdAt/expiresAt`.
  - Ключи: staged → `tmp/{uploaderId}/{id}.{ext}`; stored → `{purpose.storagePrefix()}/{ownerId}/{id}.{ext}`.
  - `FakePurpose` (тест-фикстура): `storagePrefix()='test/fake'`, `key()='test.fake'`, `constraints()` = 15 МБ, `['image/png','application/pdf']`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\File;

use App\Shared\Domain\File\StoredFile;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\File\FakePurpose;
use PHPUnit\Framework\TestCase;

final class StoredFileTest extends TestCase
{
    public function testStagedBuildsTmpKeyAndCarriesTtl(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $file = StoredFile::staged('uuid-1', 'user-9', 'photo.png', 'image/png', 'png', 2048, $now, $now->modify('+2 hours'));

        self::assertSame(StoredFile::STATUS_STAGED, $file->status());
        self::assertSame('tmp/user-9/uuid-1.png', $file->storageKey());
        self::assertNull($file->purpose());
        self::assertNull($file->ownerId());
        self::assertEquals($now->modify('+2 hours'), $file->expiresAt());
        self::assertSame(2048, $file->size());
    }

    public function testStoredBuildsPurposeKeyAndNoTtl(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $file = StoredFile::stored('uuid-2', new FakePurpose(), 'owner-7', 'doc.pdf', 'application/pdf', 'pdf', 4096, $now);

        self::assertSame(StoredFile::STATUS_STORED, $file->status());
        self::assertSame('test/fake/owner-7/uuid-2.pdf', $file->storageKey());
        self::assertSame('test.fake', $file->purpose());
        self::assertSame('owner-7', $file->ownerId());
        self::assertNull($file->expiresAt());
    }

    public function testPromoteMovesStagedToStored(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $file = StoredFile::staged('uuid-3', 'user-9', 'photo.png', 'image/png', 'png', 2048, $now, $now->modify('+2 hours'));

        $file->promote(new FakePurpose(), 'owner-7');

        self::assertSame(StoredFile::STATUS_STORED, $file->status());
        self::assertSame('test.fake', $file->purpose());
        self::assertSame('owner-7', $file->ownerId());
        self::assertSame('test/fake/owner-7/uuid-3.png', $file->storageKey());
        self::assertNull($file->expiresAt());
    }

    public function testDoublePromoteRejected(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $file = StoredFile::staged('uuid-4', 'user-9', 'photo.png', 'image/png', 'png', 2048, $now, $now->modify('+2 hours'));
        $file->promote(new FakePurpose(), 'owner-7');

        $this->expectException(AppException::class);
        $file->promote(new FakePurpose(), 'owner-8');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd app && vendor/bin/phpunit tests/Unit/Shared/File/StoredFileTest.php`
Expected: FAIL — `FilePurpose`/`StoredFile`/`FakePurpose` not found.

- [ ] **Step 3: Write minimal implementation**

`src/Shared/Domain/File/FilePurpose.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\File;

interface FilePurpose
{
    /** Префикс каталога в хранилище, напр. 'certificates/scan'. */
    public function storagePrefix(): string;

    /** Стабильный ключ назначения для реестра, напр. 'certificate.scan'. */
    public function key(): string;

    public function constraints(): FileConstraints;
}
```

`src/Shared/Domain/File/StoredFile.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\File;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Запись реестра файлов. Мутируется только на promote (tmp → привязанный к сущности).
 * Форма ключа хранилища живёт здесь; физику (байты) держит FlysystemFileStorage.
 */
final class StoredFile
{
    public const STATUS_STAGED = 'staged';
    public const STATUS_STORED = 'stored';

    private function __construct(
        private readonly string $id,
        private string $status,
        private ?string $purpose,
        private ?string $ownerId,
        private readonly ?string $uploaderId,
        private readonly string $originalName,
        private readonly string $mime,
        private readonly string $extension,
        private readonly int $size,
        private string $storageKey,
        private readonly \DateTimeImmutable $createdAt,
        private ?\DateTimeImmutable $expiresAt,
    ) {
    }

    public static function staged(
        string $id,
        string $uploaderId,
        string $originalName,
        string $mime,
        string $extension,
        int $size,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $expiresAt,
    ): self {
        return new self(
            $id, self::STATUS_STAGED, null, null, $uploaderId,
            $originalName, $mime, $extension, $size,
            self::tmpKey($uploaderId, $id, $extension), $createdAt, $expiresAt,
        );
    }

    public static function stored(
        string $id,
        FilePurpose $purpose,
        string $ownerId,
        string $originalName,
        string $mime,
        string $extension,
        int $size,
        \DateTimeImmutable $createdAt,
    ): self {
        return new self(
            $id, self::STATUS_STORED, $purpose->key(), $ownerId, null,
            $originalName, $mime, $extension, $size,
            self::storedKey($purpose, $ownerId, $id, $extension), $createdAt, null,
        );
    }

    public function promote(FilePurpose $purpose, string $ownerId): void
    {
        if (self::STATUS_STAGED !== $this->status) {
            throw new AppException('Файл уже привязан и не может быть привязан повторно.');
        }
        $this->status = self::STATUS_STORED;
        $this->purpose = $purpose->key();
        $this->ownerId = $ownerId;
        $this->storageKey = self::storedKey($purpose, $ownerId, $this->id, $this->extension);
        $this->expiresAt = null;
    }

    private static function tmpKey(string $uploaderId, string $id, string $ext): string
    {
        return sprintf('tmp/%s/%s.%s', $uploaderId, $id, $ext);
    }

    private static function storedKey(FilePurpose $purpose, string $ownerId, string $id, string $ext): string
    {
        return sprintf('%s/%s/%s.%s', $purpose->storagePrefix(), $ownerId, $id, $ext);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function purpose(): ?string
    {
        return $this->purpose;
    }

    public function ownerId(): ?string
    {
        return $this->ownerId;
    }

    public function uploaderId(): ?string
    {
        return $this->uploaderId;
    }

    public function originalName(): string
    {
        return $this->originalName;
    }

    public function mime(): string
    {
        return $this->mime;
    }

    public function extension(): string
    {
        return $this->extension;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function storageKey(): string
    {
        return $this->storageKey;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
```

`tests/Support/File/FakePurpose.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support\File;

use App\Shared\Domain\File\FileConstraints;
use App\Shared\Domain\File\FilePurpose;

final class FakePurpose implements FilePurpose
{
    public function storagePrefix(): string
    {
        return 'test/fake';
    }

    public function key(): string
    {
        return 'test.fake';
    }

    public function constraints(): FileConstraints
    {
        return new FileConstraints(15 * 1024 * 1024, ['image/png', 'application/pdf']);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd app && vendor/bin/phpunit tests/Unit/Shared/File/StoredFileTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Shared/Domain/File/FilePurpose.php src/Shared/Domain/File/StoredFile.php tests/Support/File/FakePurpose.php tests/Unit/Shared/File/StoredFileTest.php
git commit -m "Реестр файла StoredFile: staged→stored промоушен и форма ключа хранилища в домене"
```

---

### Task 3: Реестр `stored_file` (репозиторий + маппинг + миграция)

**Files:**
- Create: `src/Shared/Domain/File/StoredFileRepositoryInterface.php`
- Create: `src/Shared/Infrastructure/Repository/StoredFileRepository.php`
- Create: `src/Shared/Infrastructure/Database/ORM/File/StoredFile.orm.xml`
- Create: `src/Shared/Infrastructure/Database/Migrations/Version20260919090000.php`
- Modify: `config/packages/doctrine.yaml` (mapping-секция `SharedFile`)
- Modify: `config/services.yaml` (алиас `StoredFileRepositoryInterface`)
- Test: `tests/Functional/Shared/File/StoredFileRepositoryTest.php`

**Interfaces:**
- Consumes: `StoredFile` (Task 2).
- Produces: `interface StoredFileRepositoryInterface { public function add(StoredFile $file): void; public function get(string $id): ?StoredFile; /** @return StoredFile[] */ public function byOwner(string $purposeKey, string $ownerId): array; /** @return StoredFile[] */ public function expiredStaged(\DateTimeImmutable $now): array; public function remove(StoredFile $file): void; }`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\File;

use App\Shared\Domain\File\StoredFile;
use App\Shared\Domain\File\StoredFileRepositoryInterface;
use App\Tests\Support\File\FakePurpose;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class StoredFileRepositoryTest extends KernelTestCase
{
    private StoredFileRepositoryInterface $repo;
    private EntityManagerInterface $em;
    /** @var list<string> */
    private array $created = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->repo = $c->get(StoredFileRepositoryInterface::class);
        $this->em = $c->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->created as $id) {
            $file = $this->em->find(StoredFile::class, $id);
            if (null !== $file) {
                $this->em->remove($file);
            }
        }
        $this->em->flush();
        parent::tearDown();
    }

    public function testPersistAndFetchByOwner(): void
    {
        $now = new \DateTimeImmutable();
        $file = StoredFile::stored('reg-1', new FakePurpose(), 'owner-1', 'a.pdf', 'application/pdf', 'pdf', 10, $now);
        $this->created[] = 'reg-1';
        $this->repo->add($file);
        $this->em->clear();

        self::assertNotNull($this->repo->get('reg-1'));
        self::assertCount(1, $this->repo->byOwner('test.fake', 'owner-1'));
        self::assertCount(0, $this->repo->byOwner('test.fake', 'owner-2'));
    }

    public function testExpiredStagedSelectsOnlyPastTmp(): void
    {
        $past = new \DateTimeImmutable('-1 hour');
        $future = new \DateTimeImmutable('+1 hour');
        $created = new \DateTimeImmutable('-2 hour');

        $stale = StoredFile::staged('reg-stale', 'u', 'x.png', 'image/png', 'png', 1, $created, $past);
        $fresh = StoredFile::staged('reg-fresh', 'u', 'y.png', 'image/png', 'png', 1, $created, $future);
        $this->created[] = 'reg-stale';
        $this->created[] = 'reg-fresh';
        $this->repo->add($stale);
        $this->repo->add($fresh);
        $this->em->clear();

        $expired = $this->repo->expiredStaged(new \DateTimeImmutable());
        $ids = array_map(static fn (StoredFile $f) => $f->id(), $expired);
        self::assertContains('reg-stale', $ids);
        self::assertNotContains('reg-fresh', $ids);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (в контейнере): `cd app && vendor/bin/phpunit tests/Functional/Shared/File/StoredFileRepositoryTest.php`
Expected: FAIL — сервис `StoredFileRepositoryInterface` / таблица `stored_file` отсутствуют.

- [ ] **Step 3: Write minimal implementation**

`src/Shared/Domain/File/StoredFileRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\File;

interface StoredFileRepositoryInterface
{
    public function add(StoredFile $file): void;

    public function get(string $id): ?StoredFile;

    /** @return StoredFile[] */
    public function byOwner(string $purposeKey, string $ownerId): array;

    /** @return StoredFile[] */
    public function expiredStaged(\DateTimeImmutable $now): array;

    public function remove(StoredFile $file): void;
}
```

`src/Shared/Infrastructure/Repository/StoredFileRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Repository;

use App\Shared\Domain\File\StoredFile;
use App\Shared\Domain\File\StoredFileRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<StoredFile> */
final class StoredFileRepository extends ServiceEntityRepository implements StoredFileRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StoredFile::class);
    }

    public function add(StoredFile $file): void
    {
        $em = $this->getEntityManager();
        $em->persist($file);
        $em->flush();
    }

    public function get(string $id): ?StoredFile
    {
        return $this->find($id);
    }

    public function byOwner(string $purposeKey, string $ownerId): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.purpose = :p')->setParameter('p', $purposeKey)
            ->andWhere('f.ownerId = :o')->setParameter('o', $ownerId)
            ->getQuery()->getResult();
    }

    public function expiredStaged(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.status = :s')->setParameter('s', StoredFile::STATUS_STAGED)
            ->andWhere('f.expiresAt < :now')->setParameter('now', $now)
            ->getQuery()->getResult();
    }

    public function remove(StoredFile $file): void
    {
        $em = $this->getEntityManager();
        $em->remove($file);
        $em->flush();
    }
}
```

`src/Shared/Infrastructure/Database/ORM/File/StoredFile.orm.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<doctrine-mapping xmlns="http://doctrine-project.org/schemas/orm/doctrine-mapping"
                  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
                  xsi:schemaLocation="http://doctrine-project.org/schemas/orm/doctrine-mapping
                  https://www.doctrine-project.org/schemas/orm/doctrine-mapping.xsd">
    <entity name="App\Shared\Domain\File\StoredFile" table="stored_file">
        <id name="id" type="string" column="id" length="36">
            <generator strategy="NONE"/>
        </id>
        <field name="status" type="string" column="status" length="16"/>
        <field name="purpose" type="string" column="purpose" length="64" nullable="true"/>
        <field name="ownerId" type="string" column="owner_id" length="64" nullable="true"/>
        <field name="uploaderId" type="string" column="uploader_id" length="64" nullable="true"/>
        <field name="originalName" type="string" column="original_name" length="255"/>
        <field name="mime" type="string" column="mime" length="255"/>
        <field name="extension" type="string" column="extension" length="16"/>
        <field name="size" type="integer" column="size"/>
        <field name="storageKey" type="string" column="storage_key" length="512"/>
        <field name="createdAt" type="datetime_immutable" column="created_at"/>
        <field name="expiresAt" type="datetime_immutable" column="expires_at" nullable="true"/>
    </entity>
</doctrine-mapping>
```

`config/packages/doctrine.yaml` — добавить в `orm.mappings` (рядом с `Audit`):

```yaml
            SharedFile:
                is_bundle: false
                type: xml
                dir: '%kernel.project_dir%/src/Shared/Infrastructure/Database/ORM/File'
                prefix: 'App\Shared\Domain\File'
                alias: SharedFile
```

`config/services.yaml` — добавить алиас (рядом с прочими репозиториями):

```yaml
  App\Shared\Domain\File\StoredFileRepositoryInterface: '@App\Shared\Infrastructure\Repository\StoredFileRepository'
```

`src/Shared/Infrastructure/Database/Migrations/Version20260919090000.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Центральный реестр файлов (Shared/File). Одна таблица на все загрузки: tmp-стадия и привязанные.
 * Идемпотентно: CREATE TABLE / INDEX IF NOT EXISTS.
 */
final class Version20260919090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create stored_file registry table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS stored_file (
                id VARCHAR(36) NOT NULL,
                status VARCHAR(16) NOT NULL,
                purpose VARCHAR(64) DEFAULT NULL,
                owner_id VARCHAR(64) DEFAULT NULL,
                uploader_id VARCHAR(64) DEFAULT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime VARCHAR(255) NOT NULL,
                extension VARCHAR(16) NOT NULL,
                size INT NOT NULL,
                storage_key VARCHAR(512) NOT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                PRIMARY KEY(id)
            )
        SQL);
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_stored_file_owner ON stored_file (purpose, owner_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_stored_file_expires ON stored_file (status, expires_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS stored_file');
    }
}
```

- [ ] **Step 4: Run migration + test to verify it passes**

Run (в контейнере):
```bash
cd app && bin/console doctrine:migrations:migrate -n
cd app && vendor/bin/phpunit tests/Functional/Shared/File/StoredFileRepositoryTest.php
```
Expected: миграция применилась; PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Shared/Domain/File/StoredFileRepositoryInterface.php src/Shared/Infrastructure/Repository/StoredFileRepository.php src/Shared/Infrastructure/Database/ORM/File/StoredFile.orm.xml src/Shared/Infrastructure/Database/Migrations/Version20260919090000.php config/packages/doctrine.yaml config/services.yaml tests/Functional/Shared/File/StoredFileRepositoryTest.php
git commit -m "Центральный реестр stored_file: таблица, ORM-маппинг и репозиторий с выборкой сирот"
```

---

### Task 4: Порт `FileStorage` + реализация store/get/readStream + конфиг Flysystem

**Files:**
- Create: `src/Shared/Domain/File/FileStorage.php`
- Create: `src/Shared/Infrastructure/File/FlysystemFileStorage.php`
- Modify: `config/packages/oneup_flysystem.yaml`
- Modify: `config/services.yaml`
- Test: `tests/Functional/Shared/File/FlysystemFileStorageTest.php`

**Interfaces:**
- Consumes: `StoredFile`, `FilePurpose`, `StoredFileRepositoryInterface` (Tasks 2-3).
- Produces: `interface FileStorage` со всеми методами подсистемы (полная сигнатура в коде ниже — последующие задачи реализуют остальные методы того же интерфейса). В этой задаче реализованы: `store(FilePurpose $purpose, string $ownerId, UploadedFile $file): StoredFile`, `get(string $uuid): ?StoredFile`, `readStream(string $uuid)`.
- **Примечание архитектуры:** порт принимает `Symfony\Component\HttpFoundation\File\UploadedFile` — прагматичная зависимость (в проекте команды и так носят `UploadedFile`, ср. `CreateDocumentCommand`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\File;

use App\Shared\Domain\File\FileStorage;
use App\Shared\Domain\File\StoredFile;
use App\Shared\Domain\File\StoredFileRepositoryInterface;
use App\Tests\Support\File\FakePurpose;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class FlysystemFileStorageTest extends KernelTestCase
{
    private FileStorage $storage;
    private FilesystemOperator $fs;
    private StoredFileRepositoryInterface $repo;
    private EntityManagerInterface $em;
    /** @var list<string> */
    private array $createdIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->storage = $c->get(FileStorage::class);
        $this->fs = $c->get('oneup_flysystem.file_storage_filesystem');
        $this->repo = $c->get(StoredFileRepositoryInterface::class);
        $this->em = $c->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->createdIds as $id) {
            $file = $this->em->find(StoredFile::class, $id);
            if (null !== $file) {
                if ($this->fs->fileExists($file->storageKey())) {
                    $this->fs->delete($file->storageKey());
                }
                $this->em->remove($file);
            }
        }
        $this->em->flush();
        parent::tearDown();
    }

    public function testStoreWritesBytesRegistersAndReadsBack(): void
    {
        $upload = $this->makeUpload('hello pdf', 'doc.pdf', 'application/pdf');
        $stored = $this->storage->store(new FakePurpose(), 'owner-1', $upload);
        $this->createdIds[] = $stored->id();

        self::assertSame(StoredFile::STATUS_STORED, $stored->status());
        self::assertStringStartsWith('test/fake/owner-1/', $stored->storageKey());
        self::assertTrue($this->fs->fileExists($stored->storageKey()));
        self::assertNotNull($this->repo->get($stored->id()));
        self::assertSame('hello pdf', stream_get_contents($this->storage->readStream($stored->id())));
    }

    public function testStoreRejectsFileViolatingPurposeConstraints(): void
    {
        // FakePurpose допускает только png/pdf — text/plain должен быть отбит.
        $upload = $this->makeUpload('nope', 'note.txt', 'text/plain');
        $this->expectException(\App\Shared\Infrastructure\Exception\AppException::class);
        $this->storage->store(new FakePurpose(), 'owner-1', $upload);
    }

    private function makeUpload(string $content, string $name, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up_');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $mime, null, true);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (в контейнере): `cd app && vendor/bin/phpunit tests/Functional/Shared/File/FlysystemFileStorageTest.php`
Expected: FAIL — сервис `FileStorage` / filesystem `file_storage` не сконфигурены.

- [ ] **Step 3: Write minimal implementation**

`src/Shared/Domain/File/FileStorage.php` (полный интерфейс — реализация методов по задачам 4-6):

```php
<?php

declare(strict_types=1);

namespace App\Shared\Domain\File;

use Symfony\Component\HttpFoundation\File\UploadedFile;

interface FileStorage
{
    /** Прямая загрузка: файл пришёл в сабмите и сразу привязан к сущности. */
    public function store(FilePurpose $purpose, string $ownerId, UploadedFile $file): StoredFile;

    /** Двухфазная загрузка: файл во временную зону с TTL, привязка — позже через promote(). */
    public function stage(string $uploaderId, UploadedFile $file): StoredFile;

    public function promote(string $uuid, FilePurpose $purpose, string $ownerId): StoredFile;

    public function get(string $uuid): ?StoredFile;

    /** @return StoredFile[] */
    public function byOwner(FilePurpose $purpose, string $ownerId): array;

    /** @return resource */
    public function readStream(string $uuid);

    /** Материализует временную локальную копию (для либ, требующих путь); вызывающий сам удаляет. */
    public function toLocalTempFile(string $uuid): string;

    public function remove(string $uuid): void;

    public function removeByOwner(FilePurpose $purpose, string $ownerId): void;

    /** Удаляет просроченные tmp-файлы (байты + записи реестра); возвращает число. */
    public function purgeExpired(): int;
}
```

`src/Shared/Infrastructure/File/FlysystemFileStorage.php` (в этой задаче — store/get/readStream + приватные хелперы; остальные методы добавят задачи 5-6):

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\File;

use App\Shared\Domain\File\FileConstraints;
use App\Shared\Domain\File\FilePurpose;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Domain\File\StoredFile;
use App\Shared\Domain\File\StoredFileRepositoryInterface;
use App\Shared\Infrastructure\Exception\AppException;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final readonly class FlysystemFileStorage implements FileStorage
{
    private const STAGE_TTL = '+2 hours';

    public function __construct(
        private FilesystemOperator $filesystem,
        private StoredFileRepositoryInterface $repository,
    ) {
    }

    public function store(FilePurpose $purpose, string $ownerId, UploadedFile $file): StoredFile
    {
        $this->validate($file, $purpose->constraints());
        $stored = StoredFile::stored(
            Uuid::v7()->toRfc4122(),
            $purpose,
            $ownerId,
            $file->getClientOriginalName(),
            $this->mime($file),
            $this->extension($file),
            (int) $file->getSize(),
            new \DateTimeImmutable(),
        );
        $this->filesystem->write($stored->storageKey(), $file->getContent());
        $this->repository->add($stored);

        return $stored;
    }

    public function get(string $uuid): ?StoredFile
    {
        return $this->repository->get($uuid);
    }

    public function readStream(string $uuid)
    {
        return $this->filesystem->readStream($this->require($uuid)->storageKey());
    }

    private function require(string $uuid): StoredFile
    {
        return $this->repository->get($uuid)
            ?? throw new AppException('Файл не найден.', Response::HTTP_NOT_FOUND);
    }

    private function validate(UploadedFile $file, FileConstraints $constraints): void
    {
        $this->validateMeta($this->mime($file), (int) $file->getSize(), $constraints);

        if (null === $constraints->maxWidth() && null === $constraints->maxHeight()) {
            return;
        }
        $dimensions = @getimagesize($file->getPathname());
        if (false === $dimensions) {
            throw new AppException('Не удалось прочитать изображение.');
        }
        [$width, $height] = $dimensions;
        if (null !== $constraints->maxWidth() && $width > $constraints->maxWidth()) {
            throw new AppException('Ширина изображения превышает допустимую.');
        }
        if (null !== $constraints->maxHeight() && $height > $constraints->maxHeight()) {
            throw new AppException('Высота изображения превышает допустимую.');
        }
    }

    private function validateMeta(string $mime, int $size, FileConstraints $constraints): void
    {
        if ($size > $constraints->maxBytes()) {
            throw new AppException('Файл превышает допустимый размер.');
        }
        if (!in_array($mime, $constraints->mimeTypes(), true)) {
            throw new AppException('Недопустимый тип файла.');
        }
    }

    private function mime(UploadedFile $file): string
    {
        return $file->getMimeType() ?? 'application/octet-stream';
    }

    private function extension(UploadedFile $file): string
    {
        return $file->guessExtension() ?? $file->getClientOriginalExtension();
    }
}
```

`config/packages/oneup_flysystem.yaml` — добавить адаптер и filesystem:

```yaml
  adapters:
    file_storage_adapter:
      local:
        location: '%file_storage_upload_dir%'
    # ... существующие адаптеры остаются
  filesystems:
    file_storage:
      adapter: file_storage_adapter
    # ... существующие filesystems остаются
```

`config/services.yaml` — параметр каталога (рядом с `document_scans_upload_dir`) и биндинг+алиас порта:

```yaml
parameters:
  file_storage_upload_dir: '%kernel.project_dir%/var/uploads/file_storage'

services:
  App\Shared\Infrastructure\File\FlysystemFileStorage:
    arguments:
      $filesystem: '@oneup_flysystem.file_storage_filesystem'

  App\Shared\Domain\File\FileStorage: '@App\Shared\Infrastructure\File\FlysystemFileStorage'
```

- [ ] **Step 4: Run test to verify it passes**

Run (в контейнере): `cd app && vendor/bin/phpunit tests/Functional/Shared/File/FlysystemFileStorageTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Shared/Domain/File/FileStorage.php src/Shared/Infrastructure/File/FlysystemFileStorage.php config/packages/oneup_flysystem.yaml config/services.yaml tests/Functional/Shared/File/FlysystemFileStorageTest.php
git commit -m "Порт FileStorage и реализация на Flysystem: прямая загрузка с валидацией по назначению"
```

---

### Task 5: Двухфазная загрузка — `stage()` + `promote()`

**Files:**
- Modify: `src/Shared/Infrastructure/File/FlysystemFileStorage.php`
- Test: `tests/Functional/Shared/File/FlysystemFileStorageTest.php` (добавить кейсы)

**Interfaces:**
- Produces (реализация уже объявленных в порте): `stage(string $uploaderId, UploadedFile $file): StoredFile` — пишет в `tmp/{uploaderId}/...`, ставит TTL; `promote(string $uuid, FilePurpose $purpose, string $ownerId): StoredFile` — валидирует по purpose, двигает файл, обновляет реестр.

- [ ] **Step 1: Write the failing test** (добавить методы в существующий `FlysystemFileStorageTest`)

```php
    public function testStagePutsFileInTmpWithTtl(): void
    {
        $upload = $this->makeUpload('png-bytes', 'p.png', 'image/png');
        $staged = $this->storage->stage('user-42', $upload);
        $this->createdIds[] = $staged->id();

        self::assertSame(StoredFile::STATUS_STAGED, $staged->status());
        self::assertStringStartsWith('tmp/user-42/', $staged->storageKey());
        self::assertNotNull($staged->expiresAt());
        self::assertTrue($this->fs->fileExists($staged->storageKey()));
    }

    public function testPromoteMovesTmpFileToOwner(): void
    {
        $upload = $this->makeUpload('png-bytes', 'p.png', 'image/png');
        $staged = $this->storage->stage('user-42', $upload);
        $this->createdIds[] = $staged->id();
        $oldKey = $staged->storageKey();

        $promoted = $this->storage->promote($staged->id(), new FakePurpose(), 'owner-5');

        self::assertSame(StoredFile::STATUS_STORED, $promoted->status());
        self::assertStringStartsWith('test/fake/owner-5/', $promoted->storageKey());
        self::assertFalse($this->fs->fileExists($oldKey));
        self::assertTrue($this->fs->fileExists($promoted->storageKey()));
    }

    public function testPromoteRejectsUnknownUuid(): void
    {
        $this->expectException(\App\Shared\Infrastructure\Exception\AppException::class);
        $this->storage->promote('no-such-uuid', new FakePurpose(), 'owner-5');
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run (в контейнере): `cd app && vendor/bin/phpunit tests/Functional/Shared/File/FlysystemFileStorageTest.php --filter 'Stage|Promote'`
Expected: FAIL — `stage`/`promote` возвращают ошибку/не реализованы.

- [ ] **Step 3: Write minimal implementation** (добавить методы в `FlysystemFileStorage`)

```php
    public function stage(string $uploaderId, UploadedFile $file): StoredFile
    {
        $now = new \DateTimeImmutable();
        $staged = StoredFile::staged(
            Uuid::v7()->toRfc4122(),
            $uploaderId,
            $file->getClientOriginalName(),
            $this->mime($file),
            $this->extension($file),
            (int) $file->getSize(),
            $now,
            $now->modify(self::STAGE_TTL),
        );
        $this->filesystem->write($staged->storageKey(), $file->getContent());
        $this->repository->add($staged);

        return $staged;
    }

    public function promote(string $uuid, FilePurpose $purpose, string $ownerId): StoredFile
    {
        $file = $this->require($uuid);
        $this->validateMeta($file->mime(), $file->size(), $purpose->constraints());

        $oldKey = $file->storageKey();
        $file->promote($purpose, $ownerId);
        $this->filesystem->move($oldKey, $file->storageKey());
        $this->repository->add($file);

        return $file;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run (в контейнере): `cd app && vendor/bin/phpunit tests/Functional/Shared/File/FlysystemFileStorageTest.php`
Expected: PASS (все кейсы, включая новые).

- [ ] **Step 5: Commit**

```bash
git add src/Shared/Infrastructure/File/FlysystemFileStorage.php tests/Functional/Shared/File/FlysystemFileStorageTest.php
git commit -m "Двухфазная загрузка: stage кладёт файл в tmp с TTL, promote привязывает к сущности"
```

---

### Task 6: Чтение и жизненный цикл — byOwner/toLocalTempFile/remove/removeByOwner/purgeExpired

**Files:**
- Modify: `src/Shared/Infrastructure/File/FlysystemFileStorage.php`
- Test: `tests/Functional/Shared/File/FlysystemFileStorageTest.php` (добавить кейсы)

**Interfaces:**
- Produces (реализация оставшихся методов порта): `byOwner`, `toLocalTempFile`, `remove`, `removeByOwner`, `purgeExpired`.

- [ ] **Step 1: Write the failing test** (добавить методы в тест)

```php
    public function testToLocalTempFileMaterializesCopy(): void
    {
        $stored = $this->storage->store(new FakePurpose(), 'owner-9', $this->makeUpload('local-bytes', 'd.pdf', 'application/pdf'));
        $this->createdIds[] = $stored->id();

        $path = $this->storage->toLocalTempFile($stored->id());
        self::assertFileExists($path);
        self::assertSame('local-bytes', file_get_contents($path));
        @unlink($path);
    }

    public function testRemoveDeletesBytesAndRegistry(): void
    {
        $stored = $this->storage->store(new FakePurpose(), 'owner-9', $this->makeUpload('x', 'd.pdf', 'application/pdf'));
        $key = $stored->storageKey();

        $this->storage->remove($stored->id());

        self::assertFalse($this->fs->fileExists($key));
        self::assertNull($this->storage->get($stored->id()));
    }

    public function testPurgeExpiredRemovesOnlyStaleTmp(): void
    {
        $fresh = $this->storage->stage('user-1', $this->makeUpload('a', 'a.png', 'image/png'));
        $this->createdIds[] = $fresh->id();
        // «Просроченный» tmp создаём напрямую через реестр с прошедшим expiresAt.
        $past = new \DateTimeImmutable('-3 hours');
        $stale = StoredFile::staged('purge-stale', 'user-1', 'b.png', 'image/png', 'png', 1, $past, new \DateTimeImmutable('-1 hour'));
        $this->fs->write($stale->storageKey(), 'b');
        $this->repo->add($stale);

        $removed = $this->storage->purgeExpired();

        self::assertGreaterThanOrEqual(1, $removed);
        self::assertNull($this->storage->get('purge-stale'));
        self::assertNotNull($this->storage->get($fresh->id()));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run (в контейнере): `cd app && vendor/bin/phpunit tests/Functional/Shared/File/FlysystemFileStorageTest.php --filter 'LocalTemp|Remove|Purge'`
Expected: FAIL — методы не реализованы.

- [ ] **Step 3: Write minimal implementation** (добавить в `FlysystemFileStorage`)

```php
    public function byOwner(FilePurpose $purpose, string $ownerId): array
    {
        return $this->repository->byOwner($purpose->key(), $ownerId);
    }

    public function toLocalTempFile(string $uuid): string
    {
        $stream = $this->readStream($uuid);
        $path = tempnam(sys_get_temp_dir(), 'file_');
        file_put_contents($path, $stream);

        return $path;
    }

    public function remove(string $uuid): void
    {
        $file = $this->repository->get($uuid);
        if (null === $file) {
            return;
        }
        $this->deleteRecord($file);
    }

    public function removeByOwner(FilePurpose $purpose, string $ownerId): void
    {
        foreach ($this->repository->byOwner($purpose->key(), $ownerId) as $file) {
            $this->deleteRecord($file);
        }
    }

    public function purgeExpired(): int
    {
        $expired = $this->repository->expiredStaged(new \DateTimeImmutable());
        foreach ($expired as $file) {
            $this->deleteRecord($file);
        }

        return count($expired);
    }

    private function deleteRecord(StoredFile $file): void
    {
        if ($this->filesystem->fileExists($file->storageKey())) {
            $this->filesystem->delete($file->storageKey());
        }
        $this->repository->remove($file);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run (в контейнере): `cd app && vendor/bin/phpunit tests/Functional/Shared/File/FlysystemFileStorageTest.php`
Expected: PASS (весь файл).

- [ ] **Step 5: Commit**

```bash
git add src/Shared/Infrastructure/File/FlysystemFileStorage.php tests/Functional/Shared/File/FlysystemFileStorageTest.php
git commit -m "Хранилище дочищено: чтение в temp-файл, удаление байтов с реестром и чистка сирот"
```

---

### Task 7: Генерик endpoint загрузки в tmp

**Files:**
- Create: `src/Shared/Application/File/StagedFileView.php`
- Create: `src/Shared/Application/File/StageFiles/StageFilesCommand.php`
- Create: `src/Shared/Application/File/StageFiles/StageFilesCommandResult.php`
- Create: `src/Shared/Application/File/StageFiles/StageFilesCommandHandler.php`
- Create: `src/Shared/Infrastructure/Controller/File/UploadStagedAction.php`
- Test: `tests/Functional/Shared/File/UploadStagedActionTest.php`

**Interfaces:**
- Consumes: `FileStorage` (Task 4), `CommandBusInterface`, `CommandInterface`, `CommandHandlerInterface`, `AuthUserFetcherInterface`.
- Produces: `POST /cabinet/file/stage` (name `app_cabinet_file_stage`), принимает `files[]`, возвращает JSON `{"files":[{"uuid","name","size","mime"}]}`; широкий guard: ≤15 МБ и mime ∈ {jpeg,png,webp,pdf}, иначе 422.
- **Авторизация:** путь под `^/cabinet` → `IS_AUTHENTICATED` (security.yaml, правка не нужна). Загрузка идёт только в tmp-зону текущего пользователя (`uploaderId` = `getAuthUserId()`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\File;

use App\Shared\Domain\File\StoredFile;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadStagedActionTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Логиним админа (как в контроллер-тестах мутаций проекта).
        $user = static::getContainer()->get(\App\Users\Domain\Repository\UserRepositoryInterface::class)
            ->findAdminForTest();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        foreach ($em->getRepository(StoredFile::class)->findBy(['status' => StoredFile::STATUS_STAGED]) as $f) {
            $em->remove($f);
        }
        $em->flush();
        parent::tearDown();
    }

    public function testStagesUploadedFileAndReturnsUuid(): void
    {
        $upload = $this->makeUpload('png-bytes', 'p.png', 'image/png');
        $this->client->request('POST', '/cabinet/file/stage', [], ['files' => [$upload]]);

        self::assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        self::assertNotEmpty($data['files'][0]['uuid']);
        self::assertSame('p.png', $data['files'][0]['name']);
    }

    public function testRejectsDisallowedMime(): void
    {
        $upload = $this->makeUpload('nope', 'n.txt', 'text/plain');
        $this->client->request('POST', '/cabinet/file/stage', [], ['files' => [$upload]]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    private function makeUpload(string $content, string $name, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up_');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, $mime, null, true);
    }
}
```

> Примечание для имплементера: способ достать тестового админа — по образцу существующих
> контроллер-тестов мутаций (см. `tests/Functional/**` с `->loginUser(...)`/`ROLE_ADMIN`).
> Если хелпера `findAdminForTest()` нет — использовать тот же приём логина, что в соседних тестах.

- [ ] **Step 2: Run test to verify it fails**

Run (в контейнере): `cd app && vendor/bin/phpunit tests/Functional/Shared/File/UploadStagedActionTest.php`
Expected: FAIL — маршрут `/cabinet/file/stage` не существует (404).

- [ ] **Step 3: Write minimal implementation**

`src/Shared/Application/File/StagedFileView.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Application\File;

final readonly class StagedFileView
{
    public function __construct(
        public string $uuid,
        public string $name,
        public int $size,
        public string $mime,
    ) {
    }
}
```

`src/Shared/Application/File/StageFiles/StageFilesCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Application\File\StageFiles;

use App\Shared\Application\Command\CommandInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class StageFilesCommand implements CommandInterface
{
    /**
     * @param list<UploadedFile> $files
     */
    public function __construct(
        public string $uploaderId,
        public array $files,
    ) {
    }
}
```

`src/Shared/Application/File/StageFiles/StageFilesCommandResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Application\File\StageFiles;

use App\Shared\Application\File\StagedFileView;

final readonly class StageFilesCommandResult
{
    /** @var list<StagedFileView> */
    public array $files;

    public function __construct(StagedFileView ...$files)
    {
        $this->files = $files;
    }
}
```

`src/Shared/Application/File/StageFiles/StageFilesCommandHandler.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Application\File\StageFiles;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\File\StagedFileView;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Генерик-загрузка в tmp-зону. Широкий guard (без знания финального назначения);
 * строгую валидацию по purpose делает FileStorage::promote при привязке к сущности.
 */
final readonly class StageFilesCommandHandler implements CommandHandlerInterface
{
    private const MAX_BYTES = 15 * 1024 * 1024;
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    public function __construct(private FileStorage $storage)
    {
    }

    public function __invoke(StageFilesCommand $command): StageFilesCommandResult
    {
        $views = [];
        foreach ($command->files as $file) {
            $this->guard($file);
            $stored = $this->storage->stage($command->uploaderId, $file);
            $views[] = new StagedFileView($stored->id(), $stored->originalName(), $stored->size(), $stored->mime());
        }

        return new StageFilesCommandResult(...$views);
    }

    private function guard(UploadedFile $file): void
    {
        if ((int) $file->getSize() > self::MAX_BYTES) {
            throw new AppException('Файл слишком большой (максимум 15 МБ).');
        }
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            throw new AppException('Недопустимый тип файла.');
        }
    }
}
```

`src/Shared/Infrastructure/Controller/File/UploadStagedAction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller\File;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\File\StageFiles\StageFilesCommand;
use App\Shared\Application\File\StageFiles\StageFilesCommandResult;
use App\Shared\Application\File\StagedFileView;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/file/stage',
    name: 'app_cabinet_file_stage',
    methods: ['POST'],
)]
final class UploadStagedAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly AuthUserFetcherInterface $authUser,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $files = array_values(array_filter(
            $request->files->all()['files'] ?? [],
            static fn ($f): bool => $f instanceof UploadedFile,
        ));

        try {
            /** @var StageFilesCommandResult $result */
            $result = $this->commandBus->execute(
                new StageFilesCommand($this->authUser->getAuthUserId(), $files),
            );
        } catch (\Exception $e) {
            $status = $e instanceof AppException ? $e->getCode() : Response::HTTP_BAD_REQUEST;

            return new JsonResponse(['error' => $e->getMessage()], $status);
        }

        return new JsonResponse([
            'files' => array_map(
                static fn (StagedFileView $v): array => [
                    'uuid' => $v->uuid,
                    'name' => $v->name,
                    'size' => $v->size,
                    'mime' => $v->mime,
                ],
                $result->files,
            ),
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run (в контейнере): `cd app && vendor/bin/phpunit tests/Functional/Shared/File/UploadStagedActionTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Shared/Application/File src/Shared/Infrastructure/Controller/File/UploadStagedAction.php tests/Functional/Shared/File/UploadStagedActionTest.php
git commit -m "Единый endpoint /cabinet/file/stage: заливает файлы в tmp-зону юзера и возвращает uuid"
```

---

### Task 8: Консольная чистка сирот `app:file:purge-expired`

**Files:**
- Create: `src/Shared/Infrastructure/Controller/Console/PurgeExpiredFilesCommand.php`
- Test: `tests/Functional/Shared/File/PurgeExpiredFilesCommandTest.php`

**Interfaces:**
- Consumes: `FileStorage::purgeExpired()` (Task 6).
- Produces: команда `app:file:purge-expired`, печатает число удалённых.
- **Планирование (ops, вне кода этого деплоя):** ставится в крон/supervisor как `bin/console app:file:purge-expired`. Symfony Scheduler в проекте нет; wiring — отдельный ops-шаг ([[reference_prod_messenger_redis_ops]]).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\File;

use App\Shared\Domain\File\StoredFile;
use App\Shared\Domain\File\StoredFileRepositoryInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeExpiredFilesCommandTest extends KernelTestCase
{
    public function testPurgesExpiredTmp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $repo = $c->get(StoredFileRepositoryInterface::class);
        $fs = $c->get('oneup_flysystem.file_storage_filesystem');
        \assert($fs instanceof FilesystemOperator);

        $stale = StoredFile::staged('cmd-stale', 'u', 'x.png', 'image/png', 'png', 1, new \DateTimeImmutable('-3 hours'), new \DateTimeImmutable('-1 hour'));
        $fs->write($stale->storageKey(), 'x');
        $repo->add($stale);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:file:purge-expired'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertNull($repo->get('cmd-stale'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (в контейнере): `cd app && vendor/bin/phpunit tests/Functional/Shared/File/PurgeExpiredFilesCommandTest.php`
Expected: FAIL — команда `app:file:purge-expired` не найдена.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller\Console;

use App\Shared\Domain\File\FileStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:file:purge-expired',
    description: 'Удаляет просроченные временные загрузки (tmp) из хранилища и реестра.',
)]
final class PurgeExpiredFilesCommand extends Command
{
    public function __construct(private readonly FileStorage $storage)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = $this->storage->purgeExpired();
        (new SymfonyStyle($input, $output))->success(sprintf('Удалено просроченных tmp-файлов: %d', $removed));

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run (в контейнере): `cd app && vendor/bin/phpunit tests/Functional/Shared/File/PurgeExpiredFilesCommandTest.php`
Expected: PASS (1 test).

- [ ] **Step 5: Финальный гейт + commit**

```bash
cd app && vendor/bin/phpunit tests/Unit/Shared/File tests/Functional/Shared/File
./run check   # style + phpstan по правилу проекта
git add src/Shared/Infrastructure/Controller/Console/PurgeExpiredFilesCommand.php tests/Functional/Shared/File/PurgeExpiredFilesCommandTest.php
git commit -m "Крон-чистка app:file:purge-expired сносит зависшие tmp-загрузки из хранилища и реестра"
```

---

## Self-Review (проверка плана против спеки)

**Покрытие спеки (§ дизайна → задача):**
- §5.1 порт `FileStorage` → Task 4 (интерфейс) + 4/5/6 (реализация).
- §5.2 `StoredFile`, `FileConstraints` → Task 2, Task 1 (рефайнмент: StoredFile — mapped-entity).
- §5.3 `FilePurpose` контракт → Task 2 (+ FakePurpose-фикстура; реальные enum-ы контекстов — Деплой 2+).
- §6 S3-ready сторожа → Global Constraints + всё IO через `FilesystemOperator`, `toLocalTempFile` (Task 6), адаптер из конфига (Task 4).
- §7 реестр `stored_file` → Task 3 (таблица/маппинг/репозиторий; INTEGER size — рефайнмент).
- §8 два потока → store (Task 4), stage/promote (Task 5), генерик endpoint + широкий guard (Task 7), чистка сирот (Task 8).
- §9 риски: двойной promote → Task 2/5 (guard по статусу); отдача не централизована — генерик endpoint только на аплоад (Task 7); миграция данных Certificates — вне scope (Деплой 2).
- §10 тесты → unit (Task 1-2), functional в контейнере (Task 3-8), `./run check` финальным гейтом.

**Плейсхолдеры:** нет TBD/TODO — каждый шаг несёт реальный код и команду запуска.

**Согласованность типов:** `FilePurpose::{storagePrefix,key,constraints}`, `StoredFile::{staged,stored,promote,storageKey,...}`, `FileStorage::{store,stage,promote,get,byOwner,readStream,toLocalTempFile,remove,removeByOwner,purgeExpired}`, `StoredFileRepositoryInterface::{add,get,byOwner,expiredStaged,remove}` — имена совпадают между задачами.

**Открытый пункт для имплементера (Task 7):** способ логина тестового админа взять из соседних контроллер-тестов мутаций (в проекте есть образец `->loginUser()` с `ROLE_ADMIN`).

---

## Реализация: факт и отклонения (Деплой 1 завершён)

Ветка `feat/file-storage-backbone` от `origin/main` (6948d78), коммиты по задачам. Гейты зелёные:
**889 unit**, **399 functional** (0 fail/0 error, 28 skipped пре-существующих), **phpstan level 6**,
**php-cs-fixer**. Тесты гонялись в изолированном compose-проекте `filestorage_wt` (своя `test_db` в
tmpfs), т.к. штатные контейнеры смонтированы на главный чекаут (там параллельно другой агент).

Отклонения от кода плана, принятые при реализации:
- `StoredFile` — **не `final`** (Doctrine lazy-ghost прокси, которые дёргает аудит-листенер на flush,
  не проксируют final-классы; как `AuditEntry`). Остальное — как в задачах.
- Тест-методы переименованы в **snake_case** (`test_...`) — конвенция php-cs-fixer проекта
  (`php_unit_method_casing`), автофикс.
- Контроллерный тест читает `data.files` — **глобальный враппер** оборачивает `JsonResponse` под `data`.
- Алиас порта `FileStorage` — **`public: true`** (backbone без потребителей контейнер инлайнит; тест не
  достаёт сервис). Снять, когда появится DI-потребитель.
- tearDown контроллер-теста глушит FK-ошибку чистки юзера (конструктор `User` создаёт `user_channel`);
  как в `DocumentControllerTest`. Косметика, БД эфемерная.
- Расписание `app:file:purge-expired` — ops (крон/supervisor), в коде Деплоя 1 не заведено.
