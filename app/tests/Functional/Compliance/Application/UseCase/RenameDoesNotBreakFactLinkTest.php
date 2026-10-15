<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\Service\ComplianceProjectionRebuilder;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Регресс рекея обязанности на item.id (см. docs/plans/compliance-rekey-itemid-design.md): переименование
 * позиции нормы — это правка label при СОХРАНЁННОМ стабильном id (round-trip формы правки требования), а не
 * новая позиция. Ключ обязанности {@see TrackedObligation::keyOf} строится от itemId, не от label, поэтому
 * факт (held/история), выданный ДО переименования, должен остаться привязан к той же обязанности ПОСЛЕ.
 *
 * Ядро проверки — ПРОЕКЦИЯ (пересобранный {@see TrackedObligation}), а не прямое сканирование
 * {@see \App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance::heldOf()}: held-скан фактов
 * стабилен по построению (факты неизменяемы, их obligationKey никто не переписывает), поэтому он не поймал
 * бы регресс, если бы ребилдер ключевал ЗАНОВО по label — получилась бы ОСИРОТЕВШАЯ под старым (label-based)
 * ключом обязанность, а под стабильным itemId-ключом её просто не было бы в проекции. Именно отсутствие
 * обязанности под стабильным ключом {@see TrackedObligation::keyOf} — это и есть ловимый регресс.
 */
final class RenameDoesNotBreakFactLinkTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private CommandBusInterface $commandBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $this->commandBus = self::getContainer()->get(CommandBusInterface::class);
    }

    public function test_renaming_norm_item_keeps_fact_held_linked(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance('Перчатки');
        $this->issueCard($p, $r, $k); // на руках 10 по позиции

        // Переименовать позицию, СОХРАНИВ её стабильный id (round-trip правки нормы).
        $requirement = static::getContainer()->get(RequirementRepositoryInterface::class)->findOneById($r);
        self::assertNotNull($requirement);
        $itemId = $requirement->getItems()[0]->id();
        $this->commandBus->execute(new SaveRequirementCommand(
            $r, 'Личная карточка учёта выдачи СИЗ', 'material', $requirement->getPositionIds()->getList(),
            [['id' => $itemId, 'label' => 'Перчатки трикотажные', 'cadenceKind' => 'periodic', 'cadenceNumber' => '1', 'cadenceUnit' => 'year', 'amount' => '10', 'unit' => 'pair', 'basis' => 'п.5']],
        ));
        static::getContainer()->get(ComplianceProjectionRebuilder::class)->rebuild(profileIds: new StringCollection($p));
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        // Ключ не изменился (тот же itemId) — ищем обязанность в ПЕРЕСОБРАННОЙ проекции по этому ключу.
        $pc = static::getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($p);
        self::assertNotNull($pc);
        $key = TrackedObligation::keyOf($r, $itemId);
        self::assertSame($k, $key, 'ключ стабилен к переименованию (тот же itemId)');

        $obligation = null;
        foreach ($pc->getObligations() as $candidate) {
            if ($candidate->key() === $key) {
                $obligation = $candidate;
                break;
            }
        }
        self::assertNotNull($obligation, 'обязанность под стабильным ключом itemId жива после переименования (не осиротела под label-ключ)');
        self::assertSame('Перчатки трикотажные', $obligation->label(), 'проекция подхватила новое имя позиции');
        self::assertEqualsWithDelta(10.0, $obligation->heldQuantity(), 1e-9, 'held факта остался привязан к той же обязанности');

        // Вторичная проверка (оставлена для полноты, но не является ядром регресса — см. докблок класса).
        self::assertEqualsWithDelta(10.0, $pc->heldOf($key), 1e-9, 'held факта остался привязан после переименования');
    }
}
