<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\Service;

use App\Compliance\Application\Service\DueSoonScanner;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Проход сроков публикует дайджест на человека по его позициям «подходит срок/просрочено». Сдвигаем «сейчас»,
 * чтобы детерминированно получить Overdue. Антифлуд снят — проверяем сам факт/счёт рассылки (возврат scan()).
 */
final class DueSoonScannerTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    public function test_emits_digest_for_person_with_overdue_item(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        ['profileId' => $profileId, 'requirementId' => $requirementId, 'key' => $key] = $this->enrollCompliance();
        // выдано 2020-01-01, цикл год → срок 2021 < 2027 → Overdue
        $this->issueCard($profileId, $requirementId, $key, '10', '2020-01-01');

        $scanner = self::getContainer()->get(DueSoonScanner::class);
        self::assertGreaterThanOrEqual(1, $scanner->scan(new \DateTimeImmutable('2027-07-01')));
    }

    public function test_emits_nothing_when_no_due_items(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $this->enrollCompliance(); // не подписано → Missing (не Soon/Overdue) → не в дайджесте

        $scanner = self::getContainer()->get(DueSoonScanner::class);
        self::assertSame(0, $scanner->scan(new \DateTimeImmutable()));
    }
}
