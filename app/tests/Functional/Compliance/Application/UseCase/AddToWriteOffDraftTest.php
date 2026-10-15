<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\UseCase\Command\AddToWriteOffDraft\AddToWriteOffDraftCommand;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * «Списать выбранные» с карточки выдачи: наполнение корзины (черновик акта списания) held-фактами выбранных
 * позиций. Создаёт черновик, если открытого нет; порция = полное количество на руках, причина пустая; повторные
 * вызовы не дублируют факт; неизвестный/без остатка ключ порций не добавляет.
 */
final class AddToWriteOffDraftTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private CommandBusInterface $commandBus;
    private ProfileComplianceRepositoryInterface $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $this->authenticateAsSystem();
    }

    public function test_creates_draft_and_adds_held_fact_as_full_portion(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k); // на руках 10

        $this->commandBus->execute(new AddToWriteOffDraftCommand($p, $r, [$k]));
        $this->reload();

        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        self::assertCount(1, $pc->getWriteOffActs(), 'черновик акта создан');
        $act = $pc->getWriteOffActs()[0];
        self::assertTrue($act->isDraft());
        $items = $pc->itemsOfWriteOffAct($act->getId());
        self::assertCount(1, $items, 'held-факт позиции добавлен порцией');
        self::assertEqualsWithDelta(10.0, $items[0]->quantity(), 1e-9, 'порция = полное количество на руках');
        self::assertNull($items[0]->reason(), 'причина пустая — укажут на странице акта');
        self::assertEqualsWithDelta(10.0, $pc->heldOf($k), 1e-9, 'эффекта нет до оформления — на руках не изменилось');
    }

    public function test_does_not_duplicate_already_added_fact(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);

        $this->commandBus->execute(new AddToWriteOffDraftCommand($p, $r, [$k]));
        $this->reload();
        $this->commandBus->execute(new AddToWriteOffDraftCommand($p, $r, [$k]));
        $this->reload();

        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        self::assertCount(1, $pc->getWriteOffActs(), 'повторный вызов не плодит акты');
        $act = $pc->getWriteOffActs()[0];
        self::assertCount(1, $pc->itemsOfWriteOffAct($act->getId()), 'факт в корзине не дублируется');
    }

    public function test_unknown_key_adds_no_portion(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k); // held по требованию есть → черновик откроется

        $this->commandBus->execute(new AddToWriteOffDraftCommand($p, $r, ['нет-такой-позиции']));
        $this->reload();

        $pc = $this->repo->findByProfile($p);
        self::assertNotNull($pc);
        $act = $pc->openWriteOffDraftFor($r);
        self::assertNotNull($act);
        self::assertCount(0, $pc->itemsOfWriteOffAct($act->getId()), 'неизвестный ключ порций не добавил');
    }

    private function reload(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
