<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance;

use App\Compliance\Domain\Repository\ObligationProjectionRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Длинное наименование позиции (ГОСТ-название СИЗ > 255 символов) не роняет пересборку проекции:
 * `compliance_obligation_projection.label` вмещает до 1000 символов (раньше varchar(255) → truncation →
 * откат пересборки).
 */
final class LongLabelRebuildTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    public function test_rebuild_with_label_over_255_persists_full_label(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();

        // Без хвостового пробела — домен делает trim(label), иначе ожидание разойдётся с сохранённым значением.
        $longLabel = rtrim(str_repeat('Длинное наименование СИЗ ', 20)); // ~499 символов — больше прежнего лимита 255
        self::assertGreaterThan(255, mb_strlen($longLabel));

        ['profileId' => $profileId] = $this->enrollCompliance($longLabel); // enroll делает rebuild внутри

        $rows = self::getContainer()->get(ObligationProjectionRepositoryInterface::class)
            ->findForProfiles(new StringCollection($profileId));
        $labels = array_map(static fn ($row): string => $row->label(), $rows);
        self::assertContains($longLabel, $labels, 'длинный label сохранился в проекции, пересборка не упала');
    }
}
