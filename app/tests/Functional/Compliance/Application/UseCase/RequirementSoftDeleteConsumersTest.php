<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Query\GetRequirement\GetRequirementQuery;
use App\Compliance\Application\UseCase\Query\GetRequirement\GetRequirementQueryResult;
use App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Развод потребителей soft-delete позиции нормы (см. {@see \App\Compliance\Domain\Aggregate\Requirement\Requirement::activeItems()}/
 * {@see \App\Compliance\Domain\Aggregate\Requirement\Requirement::mergeItems()}): убранная из формы позиция
 * перестаёт быть активной обязанностью (дашборд/проекция её не держат), но держится в составе как надгробие —
 * уже выданный на руки факт остаётся списываемым и резолвится по имени, а не по сырому ключу.
 */
final class RequirementSoftDeleteConsumersTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private CommandBusInterface $commandBus;
    private QueryBusInterface $queryBus;
    private RequirementRepositoryInterface $requirementRepository;
    private ProfileComplianceRepositoryInterface $profileComplianceRepository;
    private ObligationProjectionRepositoryInterface $projectionRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->queryBus = $c->get(QueryBusInterface::class);
        $this->requirementRepository = $c->get(RequirementRepositoryInterface::class);
        $this->profileComplianceRepository = $c->get(ProfileComplianceRepositoryInterface::class);
        $this->projectionRepository = $c->get(ObligationProjectionRepositoryInterface::class);
        $this->authenticateAsSystem();
    }

    public function test_removed_but_issued_material_item_stays_writeoffable_named_and_uncontrolled(): void
    {
        // норма из двух материальных позиций; обе выданы человеку
        ['profileId' => $p, 'requirementId' => $r] = $this->enrollCompliance(
            'Перчатки',
            extraItems: [['label' => 'Ботинки', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '1', 'unit' => 'pair', 'basis' => 'п.9']],
        );
        $glovesKey = $this->obligationKeyFor($r, 'Перчатки');
        $bootsKey = $this->obligationKeyFor($r, 'Ботинки');
        $this->issueCard($p, $r, $glovesKey);
        $this->issueCard($p, $r, $bootsKey, '1');

        // убрали «Перчатки» из нормы (пере-сохранили требование без них) → мягкое удаление
        $this->saveRequirementWithoutItem($r, 'Перчатки');

        // 1) held перчаток всё ещё списываем (eligible по всем Material, не по активной норме)
        $compliance = $this->profileComplianceRepository->findByProfile($p);
        self::assertNotNull($compliance);
        $requirement = $this->requirementRepository->findOneById($r);
        self::assertNotNull($requirement);
        $eligible = $compliance->writeOffEligibleFactsForRequirement($requirement, null);
        self::assertNotEmpty(
            array_filter($eligible, static fn ($f) => $f->obligationKey() === $glovesKey),
            'held удалённой инвентарной позиции остаётся списываемым',
        );

        // 2) имя резолвится (не сырой ключ)
        self::assertSame('Перчатки', $compliance->obligationLabelOf($requirement, $glovesKey));

        // 3) дашборд/проекция не держат её активной обязанностью
        static::getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($p));
        $active = array_filter(
            $this->projectionRepository->findForProfiles(new StringCollection($p)),
            static fn ($o) => $o->requirementId() === $r && 'Перчатки' === $o->label() && $o->isActive(),
        );
        self::assertEmpty($active, 'удалённая позиция не активная обязанность');

        // Ботинки остались в норме активной позицией — вытеснение затронуло только «Перчатки».
        $activeLabels = array_map(static fn ($i) => $i->label(), $requirement->activeItems());
        self::assertSame(['Ботинки'], $activeLabels);
    }

    /** Пере-сохранить требование без позиции с $removedLabel: активные позиции формы минус удаляемая, id сохранён (round-trip). */
    private function saveRequirementWithoutItem(string $requirementId, string $removedLabel): void
    {
        /** @var GetRequirementQueryResult $result */
        $result = $this->queryBus->execute(new GetRequirementQuery($requirementId));
        self::assertNotNull($result->requirement);

        $items = [];
        foreach ($result->requirement->items as $item) {
            if ($item->label === $removedLabel) {
                continue;
            }
            $items[] = [
                'id' => $item->id,
                'label' => $item->label,
                'type' => $item->type,
                'cadenceKind' => $item->cadenceKind,
                'cadenceNumber' => null !== $item->cadenceNumber ? (string) $item->cadenceNumber : '',
                'cadenceUnit' => $item->cadenceUnit,
                'amount' => null !== $item->amount ? (string) $item->amount : '',
                'unit' => $item->unit,
                'basis' => $item->basis,
            ];
        }

        $requirement = $this->requirementRepository->findOneById($requirementId);
        self::assertNotNull($requirement);
        $this->commandBus->execute(new SaveRequirementCommand(
            $requirementId,
            $result->requirement->name,
            $result->requirement->type,
            $requirement->getPositionIds()->getList(),
            $items,
        ));
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
