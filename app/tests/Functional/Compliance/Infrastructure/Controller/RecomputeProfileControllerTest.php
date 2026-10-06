<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Controller;

use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Админ может принудительно пересобрать проекцию человека из кабинета (ручной fallback, когда async-событие
 * пересчёта пропало/упало). Синхронно: кнопка → POST → rebuild → редирект.
 */
final class RecomputeProfileControllerTest extends WebTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    public function test_admin_recompute_rebuilds_projection(): void
    {
        $client = static::createClient();
        $c = $client->getContainer();
        $admin = new User(new Email('rc_'.uniqid('', true).'@example.com'));
        $admin->setPassword('pw', $c->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty($admin, 'isActive'))->setValue($admin, true);
        (new \ReflectionProperty($admin, 'roles'))->setValue($admin, ['ROLE_ADMIN']);
        $em = $c->get(EntityManagerInterface::class);
        $em->persist($admin);
        $em->flush();
        $client->loginUser($admin);
        $this->authenticateAsSystem(); // прямые commandBus enroll — системный принципал

        ['profileId' => $profileId] = $this->enrollCompliance();

        // Рассинхрон: убираем все обязанности из проекции напрямую — recompute должен их восстановить из нормы.
        $repo = $c->get(ProfileComplianceRepositoryInterface::class);
        $pc = $repo->findByProfile($profileId);
        self::assertNotNull($pc);
        self::assertNotEmpty($pc->getObligations());
        $em->getConnection()->executeStatement(
            'DELETE FROM compliance_tracked_obligation WHERE profile_compliance_id = :id',
            ['id' => $pc->getId()]
        );
        $em->clear();

        $client->request('POST', sprintf('/cabinet/compliance/person/%s/recompute', $profileId));
        self::assertResponseRedirects();

        $em->clear();
        $pcAfter = $repo->findByProfile($profileId);
        self::assertNotNull($pcAfter);
        self::assertNotEmpty($pcAfter->getObligations(), 'проекция восстановлена пересчётом');
    }
}
