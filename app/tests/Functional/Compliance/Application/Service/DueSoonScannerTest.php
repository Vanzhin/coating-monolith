<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\Service;

use App\Compliance\Application\Service\DueSoonScanner;
use App\Compliance\Domain\Repository\DueNotificationStateRepositoryInterface;
use App\Compliance\Domain\Type\ComplianceBucket;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Проход сроков: подписанная карточка (выдано 01.06.2026, цикл год → срок 01.06.2027). Сдвигаем «сейчас»,
 * чтобы детерминированно получить Soon/Overdue. Проверяем транзишн-логику маркера: шлём на ухудшении, молчим
 * на неизменном, снова шлём при переходе в Overdue; «прайм» засевает без рассылки.
 */
final class DueSoonScannerTest extends KernelTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    public function test_notifies_on_worsening_then_silent_then_notifies_again(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        ['profileId' => $profileId, 'requirementId' => $requirementId, 'key' => $key] = $this->enrollCompliance();
        $this->issueCard($profileId, $requirementId, $key);

        $scanner = self::getContainer()->get(DueSoonScanner::class);
        $states = self::getContainer()->get(DueNotificationStateRepositoryInterface::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        // 1) Срок 01.06.2027, «сейчас» 15.05.2027 → Soon, первое вхождение → уведомили
        $scanner->scan(new \DateTimeImmutable('2027-05-15'));
        $em->clear();
        $marker = $states->findOne($profileId, $key);
        self::assertNotNull($marker);
        self::assertSame(ComplianceBucket::Soon, $marker->bucket());
        self::assertSame('2027-05-15', $marker->lastNotifiedAt()?->format('Y-m-d'));

        // 2) По-прежнему Soon, не ухудшилось → молчим (дата последнего уведомления НЕ двигается)
        $scanner->scan(new \DateTimeImmutable('2027-05-16'));
        $em->clear();
        $marker = $states->findOne($profileId, $key);
        self::assertNotNull($marker);
        self::assertSame(ComplianceBucket::Soon, $marker->bucket());
        self::assertSame('2027-05-15', $marker->lastNotifiedAt()?->format('Y-m-d'), 'повтор неизменного состояния не шлётся');

        // 3) «сейчас» 01.07.2027 → просрочено, Soon→Overdue ухудшение → снова уведомили
        $scanner->scan(new \DateTimeImmutable('2027-07-01'));
        $em->clear();
        $marker = $states->findOne($profileId, $key);
        self::assertNotNull($marker);
        self::assertSame(ComplianceBucket::Overdue, $marker->bucket());
        self::assertSame('2027-07-01', $marker->lastNotifiedAt()?->format('Y-m-d'));
    }

    public function test_prime_seeds_marker_without_notifying(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        ['profileId' => $profileId, 'requirementId' => $requirementId, 'key' => $key] = $this->enrollCompliance();
        $this->issueCard($profileId, $requirementId, $key);

        $scanner = self::getContainer()->get(DueSoonScanner::class);
        $states = self::getContainer()->get(DueNotificationStateRepositoryInterface::class);
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $scanner->scan(new \DateTimeImmutable('2027-05-15'), emit: false);
        $em->clear();
        $marker = $states->findOne($profileId, $key);
        self::assertNotNull($marker);
        self::assertSame(ComplianceBucket::Soon, $marker->bucket());
        self::assertNull($marker->lastNotifiedAt(), 'прайм засевает состояние без рассылки');
    }
}
