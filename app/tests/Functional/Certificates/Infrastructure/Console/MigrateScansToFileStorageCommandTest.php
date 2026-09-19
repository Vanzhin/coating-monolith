<?php

declare(strict_types=1);

namespace App\Tests\Functional\Certificates\Infrastructure\Console;

use App\Certificates\Domain\Aggregate\Document\Document;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\Reference;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Aggregate\Issuer\Specification\IssuerSpecification;
use App\Shared\Domain\File\StoredFile;
use App\Shared\Domain\File\StoredFileRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class MigrateScansToFileStorageCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private StoredFileRepositoryInterface $registry;
    private FilesystemOperator $scansFs;
    private FilesystemOperator $fileStorageFs;
    private IssuerSpecification $issuerSpec;

    /** @var list<string> */
    private array $docIds = [];
    /** @var list<Uuid> */
    private array $issuerIds = [];
    /** @var list<string> */
    private array $storedIds = [];
    /** @var list<array{0: FilesystemOperator, 1: string}> */
    private array $files = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->registry = $c->get(StoredFileRepositoryInterface::class);
        $this->scansFs = $c->get('oneup_flysystem.document_scans_filesystem');
        $this->fileStorageFs = $c->get('oneup_flysystem.file_storage_filesystem');
        $this->issuerSpec = $c->get(IssuerSpecification::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        try {
            foreach ($this->files as [$fs, $key]) {
                if ($fs->fileExists($key)) {
                    $fs->delete($key);
                }
            }
            foreach ($this->storedIds as $id) {
                $stored = $this->em->find(StoredFile::class, $id);
                if (null !== $stored) {
                    $this->em->remove($stored);
                }
            }
            foreach ($this->docIds as $id) {
                $doc = $this->em->find(Document::class, Uuid::fromString($id));
                if (null !== $doc) {
                    $this->em->remove($doc);
                }
            }
            foreach ($this->issuerIds as $id) {
                $issuer = $this->em->find(Issuer::class, $id);
                if (null !== $issuer) {
                    $this->em->remove($issuer);
                }
            }
            $this->em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_migrates_old_key_to_registry_and_new_layout(): void
    {
        $scanUuid = Uuid::v7()->toRfc4122();
        $docId = $this->makeDocWithScan($scanUuid.'.pdf');
        $this->storedIds[] = $scanUuid;

        $tester = $this->runCommand();
        $tester->assertCommandIsSuccessful();

        $this->em->clear();
        $stored = $this->registry->get($scanUuid);
        self::assertNotNull($stored);
        self::assertSame('certificate.scan', $stored->purpose());

        $newKey = 'certificates/scan/'.$docId.'/'.$scanUuid.'.pdf';
        $this->files[] = [$this->fileStorageFs, $newKey];
        self::assertSame($newKey, $stored->storageKey());
        self::assertTrue($this->fileStorageFs->fileExists($newKey));

        $reloaded = $this->em->find(Document::class, $docId);
        self::assertNotNull($reloaded);
        self::assertSame($scanUuid, $reloaded->getFile());

        // Идемпотентность: повторный прогон пропускает уже перенесённый (file уже uuid, без .pdf).
        $again = $this->runCommand();
        $again->assertCommandIsSuccessful();
        self::assertStringContainsString('пропущено: 1', $again->getDisplay());
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $scanUuid = Uuid::v7()->toRfc4122();
        $docId = $this->makeDocWithScan($scanUuid.'.pdf');

        $tester = $this->runCommand(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('dry-run', $tester->getDisplay());

        // Ничего не записано: ни реестр, ни новый файл, Document.file не тронут.
        $this->em->clear();
        self::assertNull($this->registry->get($scanUuid));
        self::assertFalse($this->fileStorageFs->fileExists('certificates/scan/'.$docId.'/'.$scanUuid.'.pdf'));
        self::assertSame($scanUuid.'.pdf', $this->em->find(Document::class, $docId)?->getFile());
    }

    public function test_missing_scan_file_is_reported_as_error(): void
    {
        // Документ ссылается на ключ, которого нет в document_scans.
        $this->makeDocWithScan(Uuid::v7()->toRfc4122().'.pdf', writeBytes: false);

        $tester = $this->runCommand();

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('ошибок: 1', $tester->getDisplay());
    }

    public function test_already_migrated_uuid_is_skipped(): void
    {
        // Document.file уже uuid (без .pdf) — считается перенесённым, не трогаем.
        $this->makeDocWithScan(Uuid::v7()->toRfc4122(), writeBytes: false);

        $tester = $this->runCommand();
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('пропущено: 1', $tester->getDisplay());
    }

    private function makeDocWithScan(string $fileRef, bool $writeBytes = true): Uuid
    {
        $issuerId = Uuid::v7();
        $this->em->persist(new Issuer($issuerId, 'Изд-'.bin2hex(random_bytes(3)), $this->issuerSpec));
        $this->issuerIds[] = $issuerId;

        if ($writeBytes) {
            $this->scansFs->write($fileRef, "%PDF-1.4\ntest\n%%EOF");
            $this->files[] = [$this->scansFs, $fileRef];
        }

        $docId = Uuid::v7();
        $doc = new Document($docId, DocumentKind::Certificate, 'mig', $issuerId, new \DateTimeImmutable(), null, 'subj', null, null, $fileRef, new Reference(ReferenceType::CoatingSystem, Uuid::v7()));
        $this->em->persist($doc);
        $this->em->flush();
        $this->docIds[] = (string) $docId;

        return $docId;
    }

    /** @param array<string, mixed> $input */
    private function runCommand(array $input = []): CommandTester
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:certificates:migrate-scans-to-file-storage'));
        $tester->execute($input);

        return $tester;
    }
}
