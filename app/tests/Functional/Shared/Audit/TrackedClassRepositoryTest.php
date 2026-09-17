<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Audit;

use App\Shared\Domain\Audit\TrackedClass;
use App\Shared\Domain\Audit\TrackedClassRepositoryInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TrackedClassRepositoryTest extends KernelTestCase
{
    public function test_save_find_remove(): void
    {
        self::bootKernel();
        $repo = self::getContainer()->get(TrackedClassRepositoryInterface::class);
        $class = 'App\\Test\\Probe'.substr(md5((string) mt_rand()), 0, 6);

        $repo->save(new TrackedClass(Uuid::uuid4()->toString(), $class, ['title' => 'Заголовок']));
        $found = $repo->findByClass($class);
        self::assertNotNull($found);
        self::assertSame(['title' => 'Заголовок'], $found->fields());

        $repo->remove($found);
        self::assertNull($repo->findByClass($class));
    }

    public function test_coating_seed(): void
    {
        self::bootKernel();
        $found = self::getContainer()->get(TrackedClassRepositoryInterface::class)
            ->findByClass('App\\Coatings\\Domain\\Aggregate\\Coating\\Coating');
        self::assertNotNull($found);
        self::assertArrayHasKey('title', $found->fields());
        self::assertArrayHasKey('minRecoatingInterval', $found->fields());
    }
}
