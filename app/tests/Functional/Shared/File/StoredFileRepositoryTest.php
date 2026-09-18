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

    public function test_persist_and_fetch_by_owner(): void
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

    public function test_expired_staged_selects_only_past_tmp(): void
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
