<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Repository;

use App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance;
use App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Type\CadenceKind;
use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Cadence;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Прямой SQL-скан проекции compliance_tracked_obligation (для прохода due-soon) — обязательные условия:
 * active=true И next_due_at задан И не позже порога. Проверяем, что каждое условие реально отсекает строки
 * (не заявлено лишнего/не пропущено нужное), а не только happy-path.
 */
final class ProfileComplianceRepositoryFindDueSoonObligationsTest extends KernelTestCase
{
    private ProfileComplianceRepositoryInterface $repo;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $this->em = $c->get(EntityManagerInterface::class);
    }

    public function test_returns_only_active_obligations_due_within_threshold(): void
    {
        $now = new \DateTimeImmutable('2027-01-01');
        $threshold = $now->modify('+60 days');

        $dueSoonId = $this->persistObligation('Срок скоро', active: true, nextDueAt: $now->modify('+10 days'));
        $overdueId = $this->persistObligation('Просрочено', active: true, nextDueAt: $now->modify('-5 days'));
        $this->persistObligation('Далёкий срок', active: true, nextDueAt: $now->modify('+120 days'));
        $this->persistObligation('Неактивно', active: false, nextDueAt: $now->modify('+10 days'));
        $this->persistObligation('Без срока', active: true, nextDueAt: null);

        $this->em->clear();

        $result = $this->repo->findDueSoonObligations($threshold);

        $labels = array_map(static fn (TrackedObligation $o): string => $o->label(), $result);
        sort($labels);
        self::assertSame(['Просрочено', 'Срок скоро'], $labels);

        $byLabel = [];
        foreach ($result as $obligation) {
            $byLabel[$obligation->label()] = $obligation;
        }
        self::assertSame($dueSoonId, $byLabel['Срок скоро']->profileCompliance()->getProfileId());
        self::assertSame($overdueId, $byLabel['Просрочено']->profileCompliance()->getProfileId());
    }

    /** Отдельный ProfileCompliance (свой profileId) на каждую позицию — чище для проверки происхождения строки. */
    private function persistObligation(string $label, bool $active, ?\DateTimeImmutable $nextDueAt): string
    {
        $profileId = 'prof-'.uniqid('', true);
        $pc = new ProfileCompliance(Uuid::v7(), $profileId);
        $obligation = new TrackedObligation(
            Uuid::v7(), $pc, 'req-'.uniqid('', true), 'item-'.uniqid('', true),
            'Требование', $label, ComplianceType::NonMaterial, new Cadence(CadenceKind::Once), null, 'dept-1',
        );
        $obligation->setDates($active ? new \DateTimeImmutable('-1 year') : null, $nextDueAt);
        $obligation->setActive($active);
        $pc->putObligation($obligation);
        $this->repo->add($pc);

        return $profileId;
    }
}
