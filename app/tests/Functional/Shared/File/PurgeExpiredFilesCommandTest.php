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
    public function test_purges_expired_tmp(): void
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
        self::assertFalse($fs->fileExists($stale->storageKey()));
    }
}
