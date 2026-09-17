<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shared\Audit;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
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

    public function test_for_class_returns_all_when_both_facets_empty(): void
    {
        [$repo, $class] = $this->seedTwoEntitiesTwoActors();

        $all = $repo->forClass($class, new StringCollection(), new StringCollection(), Pager::fromPage(1, 10));

        self::assertCount(3, $all);
    }

    public function test_for_class_filters_by_actor_ids(): void
    {
        [$repo, $class] = $this->seedTwoEntitiesTwoActors();

        $onlyActor1 = $repo->forClass($class, new StringCollection('actor-1'), new StringCollection(), Pager::fromPage(1, 10));

        self::assertCount(2, $onlyActor1);
        foreach ($onlyActor1 as $entry) {
            self::assertSame('actor-1', $entry->actorId());
        }
    }

    public function test_for_class_filters_by_entity_ids(): void
    {
        [$repo, $class, $eid1, $eid2] = $this->seedTwoEntitiesTwoActors();

        $onlyEid2 = $repo->forClass($class, new StringCollection(), new StringCollection($eid2), Pager::fromPage(1, 10));

        self::assertCount(1, $onlyEid2);
        self::assertSame($eid2, $onlyEid2[0]->entityId());
    }

    public function test_for_class_combines_actor_and_entity_facets(): void
    {
        [$repo, $class, $eid1] = $this->seedTwoEntitiesTwoActors();

        $combined = $repo->forClass(
            $class,
            new StringCollection('actor-1'),
            new StringCollection($eid1),
            Pager::fromPage(1, 10),
        );

        self::assertCount(1, $combined);
        self::assertSame('actor-1', $combined[0]->actorId());
        self::assertSame($eid1, $combined[0]->entityId());
    }

    /**
     * Три записи одного класса: (eid1,actor-1), (eid1,actor-2), (eid2,actor-1) —
     * покрывает фильтр по актору, по покрытию и их комбинацию.
     *
     * @return array{0: AuditEntryRepositoryInterface, 1: string, 2: string, 3: string}
     */
    private function seedTwoEntitiesTwoActors(): array
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repo = self::getContainer()->get(AuditEntryRepositoryInterface::class);
        $class = 'App\\Y'.substr(md5((string) mt_rand()), 0, 8);
        $eid1 = 'c-'.substr(md5((string) mt_rand()), 0, 8);
        $eid2 = 'c-'.substr(md5((string) mt_rand()), 0, 8);

        $em->persist(new AuditEntry(Uuid::uuid4()->toString(), $class, $eid1, AuditAction::Updated,
            new ChangeSet(FieldChange::set('title', 'a', 'b')), 'actor-1', new \DateTimeImmutable()));
        $em->flush();
        $em->persist(new AuditEntry(Uuid::uuid4()->toString(), $class, $eid1, AuditAction::Updated,
            new ChangeSet(FieldChange::set('title', 'b', 'c')), 'actor-2', new \DateTimeImmutable()));
        $em->flush();
        $em->persist(new AuditEntry(Uuid::uuid4()->toString(), $class, $eid2, AuditAction::Updated,
            new ChangeSet(FieldChange::set('title', 'c', 'd')), 'actor-1', new \DateTimeImmutable()));
        $em->flush();

        return [$repo, $class, $eid1, $eid2];
    }
}
