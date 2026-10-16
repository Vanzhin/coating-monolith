<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Repository;

use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Compliance\Domain\Type\WriteOffReason;
use App\Shared\Domain\ValueObject\Commission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

final class WriteOffActRepositoryTest extends KernelTestCase
{
    private WriteOffActRepositoryInterface $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get(WriteOffActRepositoryInterface::class);
    }

    public function test_round_trip_cross_person_lines(): void
    {
        $reqId = 'req-'.uniqid('', true);
        $orgId = 'org-'.uniqid('', true);
        $act = new WriteOffAct(Uuid::v7(), $reqId, $orgId, new \DateTimeImmutable('2026-10-20'));
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);
        $act->addLinesForProfile('p2', [['recordId' => 'r2', 'labelSnapshot' => 'Каска', 'quantity' => 1.0]]);
        $actId = $act->getId();

        $this->repo->add($act);
        $this->clear();

        $loaded = $this->repo->findById($actId);
        self::assertNotNull($loaded);
        self::assertCount(2, $loaded->lines());
        self::assertSame(['p1', 'p2'], $loaded->profileIds());

        $draft = $this->repo->findOpenDraft($reqId, $orgId);
        self::assertNotNull($draft);
        self::assertSame($actId, $draft->getId());
    }

    public function test_signed_act_is_not_returned_as_open_draft(): void
    {
        $reqId = 'req-'.uniqid('', true);
        $orgId = 'org-'.uniqid('', true);
        $act = new WriteOffAct(Uuid::v7(), $reqId, $orgId, new \DateTimeImmutable('2026-10-20'));
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);
        $act->replacePortions([['recordId' => 'r1', 'quantity' => 10.0, 'reason' => WriteOffReason::PhysicalWear]]);
        $act->sign(Commission::fromRows([['fio' => 'Петров']]), '39', new \DateTimeImmutable('2026-10-20'), 'scan-1', new \DateTimeImmutable('2026-10-20'));
        $this->repo->add($act);
        $this->clear();

        self::assertNull($this->repo->findOpenDraft($reqId, $orgId), 'подписанный акт не черновик — не возвращается как открытый');
    }

    public function test_find_drafts_containing_record(): void
    {
        $reqId = 'req-'.uniqid('', true);
        $orgId = 'org-'.uniqid('', true);
        $act = new WriteOffAct(Uuid::v7(), $reqId, $orgId, new \DateTimeImmutable('2026-10-20'));
        $act->addLinesForProfile('p1', [['recordId' => 'r1', 'labelSnapshot' => 'Перчатки', 'quantity' => 10.0]]);
        $this->repo->add($act);
        $this->clear();

        $found = $this->repo->findDraftsContainingRecord('r1');
        self::assertCount(1, $found);
        self::assertSame($act->getId(), $found[0]->getId());
        self::assertCount(1, $found[0]->lines(), 'весь состав строк акта, не только совпавшая');

        self::assertSame([], $this->repo->findDraftsContainingRecord('no-such-record'));
    }

    public function test_remove(): void
    {
        $act = new WriteOffAct(Uuid::v7(), 'req-'.uniqid('', true), 'org-'.uniqid('', true), new \DateTimeImmutable('2026-10-20'));
        $this->repo->add($act);
        $actId = $act->getId();

        $this->repo->remove($act);
        $this->clear();

        self::assertNull($this->repo->findById($actId));
    }

    private function clear(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
