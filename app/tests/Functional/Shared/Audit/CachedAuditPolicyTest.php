<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Audit;

use App\Shared\Domain\Audit\AuditFieldKind;
use App\Shared\Domain\Audit\AuditPolicyInterface;
use App\Shared\Domain\Audit\TrackedClass;
use App\Shared\Domain\Audit\TrackedClassRepositoryInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CachedAuditPolicyTest extends KernelTestCase
{
    public function test_reads_and_invalidates(): void
    {
        self::bootKernel();
        $policy = self::getContainer()->get(AuditPolicyInterface::class);
        $repo = self::getContainer()->get(TrackedClassRepositoryInterface::class);
        $class = 'App\\Test\\Policy'.substr(md5((string) mt_rand()), 0, 6);

        self::assertSame([], $policy->trackedFields($class));
        $repo->save(new TrackedClass(Uuid::uuid4()->toString(), $class, ['title' => 'Заголовок']));
        $policy->invalidate($class);
        self::assertSame(['title' => 'Заголовок'], $policy->trackedFields($class));
    }

    public function test_field_kinds_are_read_as_enum_map_and_invalidated(): void
    {
        self::bootKernel();
        $policy = self::getContainer()->get(AuditPolicyInterface::class);
        $repo = self::getContainer()->get(TrackedClassRepositoryInterface::class);
        $class = 'App\\Test\\PolicyKind'.substr(md5((string) mt_rand()), 0, 6);

        self::assertSame([], $policy->fieldKinds($class));
        $repo->save(new TrackedClass(Uuid::uuid4()->toString(), $class, [
            'title' => 'Заголовок',
            'dftRange' => ['label' => 'Толщина плёнки (DFT)', 'kind' => 'dft'],
        ]));
        $policy->invalidate($class);

        self::assertSame(
            ['title' => AuditFieldKind::Scalar, 'dftRange' => AuditFieldKind::Dft],
            $policy->fieldKinds($class),
        );
    }
}
