<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\File;

use App\Shared\Domain\File\FileStorage;
use App\Shared\Domain\File\StoredFile;
use App\Shared\Domain\File\StoredFileRepositoryInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\File\FakePurpose;
use App\Tests\Support\File\Max1x1ImagePurpose;
use App\Tests\Support\File\TinyBytesPurpose;
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

    public function test_store_writes_bytes_registers_and_reads_back(): void
    {
        $bytes = $this->pdfBytes();
        $stored = $this->storage->store(new FakePurpose(), 'owner-1', $this->upload($bytes, 'doc.pdf'));
        $this->createdIds[] = $stored->id();

        self::assertSame(StoredFile::STATUS_STORED, $stored->status());
        self::assertStringStartsWith('test/fake/owner-1/', $stored->storageKey());
        self::assertSame('application/pdf', $stored->mime());
        self::assertTrue($this->fs->fileExists($stored->storageKey()));
        self::assertNotNull($this->repo->get($stored->id()));
        self::assertSame($bytes, stream_get_contents($this->storage->readStream($stored->id())));
    }

    public function test_store_rejects_file_violating_purpose_constraints(): void
    {
        // FakePurpose допускает только png/pdf — текст (text/plain по содержимому) должен быть отбит.
        $this->expectException(AppException::class);
        $this->storage->store(new FakePurpose(), 'owner-1', $this->upload('just text', 'note.txt'));
    }

    public function test_stage_puts_file_in_tmp_with_ttl(): void
    {
        $staged = $this->storage->stage('user-42', $this->upload($this->pngBytes(), 'p.png'));
        $this->createdIds[] = $staged->id();

        self::assertSame(StoredFile::STATUS_STAGED, $staged->status());
        self::assertStringStartsWith('tmp/user-42/', $staged->storageKey());
        self::assertNotNull($staged->expiresAt());
        self::assertTrue($this->fs->fileExists($staged->storageKey()));
    }

    public function test_promote_moves_tmp_file_to_owner(): void
    {
        $staged = $this->storage->stage('user-42', $this->upload($this->pngBytes(), 'p.png'));
        $this->createdIds[] = $staged->id();
        $oldKey = $staged->storageKey();

        $promoted = $this->storage->promote($staged->id(), new FakePurpose(), 'owner-5');

        self::assertSame(StoredFile::STATUS_STORED, $promoted->status());
        self::assertStringStartsWith('test/fake/owner-5/', $promoted->storageKey());
        self::assertFalse($this->fs->fileExists($oldKey));
        self::assertTrue($this->fs->fileExists($promoted->storageKey()));
    }

    public function test_promote_rejects_unknown_uuid(): void
    {
        $this->expectException(AppException::class);
        $this->storage->promote('no-such-uuid', new FakePurpose(), 'owner-5');
    }

    public function test_to_local_temp_file_materializes_copy(): void
    {
        $bytes = $this->pdfBytes();
        $stored = $this->storage->store(new FakePurpose(), 'owner-9', $this->upload($bytes, 'd.pdf'));
        $this->createdIds[] = $stored->id();

        $path = $this->storage->toLocalTempFile($stored->id());
        self::assertFileExists($path);
        self::assertSame($bytes, file_get_contents($path));
        @unlink($path);
    }

    public function test_remove_deletes_bytes_and_registry(): void
    {
        $stored = $this->storage->store(new FakePurpose(), 'owner-9', $this->upload($this->pdfBytes(), 'd.pdf'));
        $key = $stored->storageKey();

        $this->storage->remove($stored->id());

        self::assertFalse($this->fs->fileExists($key));
        self::assertNull($this->storage->get($stored->id()));
    }

    public function test_purge_expired_removes_only_stale_tmp(): void
    {
        $fresh = $this->storage->stage('user-1', $this->upload($this->pngBytes(), 'a.png'));
        $this->createdIds[] = $fresh->id();

        $stale = StoredFile::staged('purge-stale', 'user-1', 'b.png', 'image/png', 'png', 1, new \DateTimeImmutable('-3 hours'), new \DateTimeImmutable('-1 hour'));
        $this->fs->write($stale->storageKey(), 'b');
        $this->repo->add($stale);

        $removed = $this->storage->purgeExpired();

        self::assertGreaterThanOrEqual(1, $removed);
        self::assertNull($this->storage->get('purge-stale'));
        self::assertNotNull($this->storage->get($fresh->id()));
    }

    public function test_store_rejects_oversize_file(): void
    {
        // TinyBytesPurpose разрешает 1 байт — pdf заведомо больше.
        $this->expectException(AppException::class);
        $this->storage->store(new TinyBytesPurpose(), 'owner-1', $this->upload($this->pdfBytes(), 'd.pdf'));
    }

    public function test_store_rejects_image_exceeding_dimensions(): void
    {
        // Max1x1ImagePurpose допускает максимум 1x1 — картинка 2x2 отбивается.
        $this->expectException(AppException::class);
        $this->storage->store(new Max1x1ImagePurpose(), 'owner-1', $this->upload($this->pngBytes(2, 2), 'big.png'));
    }

    public function test_promote_enforces_purpose_image_dimensions(): void
    {
        // Габариты в stage не проверяются (широкий guard) — 2x2 стейджится, но promote под
        // Max1x1ImagePurpose обязан отбить по dimension-констрейнту.
        $staged = $this->storage->stage('user-7', $this->upload($this->pngBytes(2, 2), 'big.png'));
        $this->createdIds[] = $staged->id();

        $this->expectException(AppException::class);
        $this->storage->promote($staged->id(), new Max1x1ImagePurpose(), 'owner-5');
    }

    private function upload(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up_');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function pdfBytes(): string
    {
        return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
    }

    private function pngBytes(int $width = 1, int $height = 1): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();

        return $bytes;
    }
}
