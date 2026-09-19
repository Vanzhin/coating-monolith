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
        $issuerId = Uuid::v7();
        $this->em->persist(new Issuer($issuerId, 'Изд-'.bin2hex(random_bytes(3)), $this->issuerSpec));
        $this->issuerIds[] = $issuerId;

        $scanUuid = Uuid::v7()->toRfc4122();
        $oldKey = $scanUuid.'.pdf';
        $this->scansFs->write($oldKey, "%PDF-1.4\ntest\n%%EOF");
        $this->files[] = [$this->scansFs, $oldKey];

        $docId = Uuid::v7();
        $doc = new Document($docId, DocumentKind::Certificate, 'mig', $issuerId, new \DateTimeImmutable(), null, 'subj', null, null, $oldKey, new Reference(ReferenceType::CoatingSystem, Uuid::v7()));
        $this->em->persist($doc);
        $this->em->flush();
        $this->docIds[] = (string) $docId;
        $this->storedIds[] = $scanUuid;

        $tester = new CommandTester((new Application(self::$kernel))->find('app:certificates:migrate-scans-to-file-storage'));
        $tester->execute([]);
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

        // Идемпотентность: повторный прогон пропускает уже перенесённый.
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('пропущено: 1', $tester->getDisplay());
    }
}
