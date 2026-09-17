<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Audit;

use App\Shared\Domain\Audit\AuditAction;
use App\Shared\Domain\Audit\AuditEntry;
use App\Shared\Domain\Audit\AuditEntryRepositoryInterface;
use App\Shared\Domain\Audit\ChangeSet;
use App\Shared\Domain\Audit\FieldChange;
use App\Shared\Domain\Repository\Pager;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AuditEntryRepositoryTest extends KernelTestCase
{
    public function test_for_entity_newest_first(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repo = self::getContainer()->get(AuditEntryRepositoryInterface::class);
        $eid = 'c-'.substr(md5((string) mt_rand()), 0, 8);

        foreach (['A', 'B'] as $i => $t) {
            $em->persist(new AuditEntry(
                Uuid::uuid4()->toString(),
                'App\\X',
                $eid,
                AuditAction::Updated,
                new ChangeSet(FieldChange::set('title', (string) $i, $t)),
                'system',
                new \DateTimeImmutable(),
            ));
            $em->flush();
        }

        $rows = $repo->forEntity('App\\X', $eid, Pager::fromPage(1, 10));
        self::assertCount(2, $rows);
        self::assertSame('B', $rows[0]->changes()->all()[0]->new); // seq DESC → B первым
        self::assertSame('A', $rows[1]->changes()->all()[0]->new);
        self::assertNotNull($rows[0]->seq());
        self::assertGreaterThan($rows[1]->seq(), $rows[0]->seq());
    }

    public function test_for_class_filters_by_actor(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repo = self::getContainer()->get(AuditEntryRepositoryInterface::class);
        $class = 'App\\Y'.substr(md5((string) mt_rand()), 0, 8);
        $eid = 'c-'.substr(md5((string) mt_rand()), 0, 8);

        $em->persist(new AuditEntry(Uuid::uuid4()->toString(), $class, $eid, AuditAction::Updated,
            new ChangeSet(FieldChange::set('title', 'a', 'b')), 'actor-1', new \DateTimeImmutable()));
        $em->flush();
        $em->persist(new AuditEntry(Uuid::uuid4()->toString(), $class, $eid, AuditAction::Updated,
            new ChangeSet(FieldChange::set('title', 'b', 'c')), 'actor-2', new \DateTimeImmutable()));
        $em->flush();

        $all = $repo->forClass($class, null, Pager::fromPage(1, 10));
        self::assertCount(2, $all);

        $onlyActor1 = $repo->forClass($class, 'actor-1', Pager::fromPage(1, 10));
        self::assertCount(1, $onlyActor1);
        self::assertSame('actor-1', $onlyActor1[0]->actorId());
    }
}
