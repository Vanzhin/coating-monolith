<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Controller;

use App\Compliance\Application\UseCase\Command\SaveWriteOffAct\SaveWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\SignWriteOffAct\SignWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\StartWriteOffAct\StartWriteOffActCommand;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Tests\Support\AuthenticatesActorTrait;
use App\Tests\Support\EnrollsComplianceTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Партиал истории позиции — грузится лениво со страницы выдачи (obligation_history_controller.js) на первое
 * раскрытие карточки. Тут проверяем сам HTTP-эндпоинт: рендерит ленту, без неё отдаёт пустое состояние.
 */
final class ObligationHistoryActionTest extends WebTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();

        $user = new User(new Email('obligation_history_'.uniqid('', true).'@example.com'));
        $user->setPassword('test_password', $container->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty($user, 'isActive'))->setValue($user, true);
        (new \ReflectionProperty($user, 'roles'))->setValue($user, ['ROLE_ADMIN']);
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);
        $this->authenticateAsSystem();
    }

    public function test_renders_empty_state_when_nothing_issued_yet(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();

        $this->client->request('GET', $this->historyUrl($p, $r, $k));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('История пока пуста', (string) $this->client->getResponse()->getContent());
    }

    public function test_renders_issuance_and_signed_write_off_in_the_feed(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k, amount: '10', date: '2026-06-01');

        $bus = static::getContainer()->get(CommandBusInterface::class);
        $bus->execute(new StartWriteOffActCommand($p, $r));
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $pc = static::getContainer()->get(ProfileComplianceRepositoryInterface::class)->findByProfile($p);
        self::assertNotNull($pc);
        $actId = $pc->openWriteOffDraftFor($r)?->getId();
        self::assertNotNull($actId);
        $recordId = $pc->getRecords()[0]->getId();

        $bus->execute(new SaveWriteOffActCommand(
            $p, $actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']], actDate: '2026-08-10',
        ));
        $bus->execute(new SignWriteOffActCommand(
            $p, $actId, [['name' => 'Петров П. П.', 'position' => 'Инженер']], '39', '2026-08-10', $this->stageComplianceScan(),
        ));
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->request('GET', $this->historyUrl($p, $r, $k));

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Выдано', $html);
        self::assertStringContainsString('01.06.2026', $html);
        self::assertStringContainsString('Списано', $html);
        self::assertStringContainsString('Физический износ', $html);
        self::assertStringContainsString('39', $html, 'номер акта виден в ленте');
    }

    /**
     * Регрессия (прод 500): obligationKey = requirementId|lower(label), label из формы может содержать «/»
     * (напр. «Каска защитная до 1000В-1500В/50дж») — путь-параметр Symfony матчит по умолчанию «[^/]++» и
     * «/» внутри ключа ломал генерацию URL в issue.html.twig. Ключ — query-параметр `key`, принимает любые
     * символы с URL-кодированием.
     */
    public function test_renders_history_for_obligation_key_containing_slash(): void
    {
        $label = 'Каска защитная до 1000В-1500В/50дж';
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance($label);
        self::assertStringContainsString('/', $k, 'ключ позиции действительно несёт «/» из наименования');
        $this->issueCard($p, $r, $k, amount: '10', date: '2026-06-01');

        $this->client->request('GET', $this->historyUrl($p, $r, $k));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Выдано', (string) $this->client->getResponse()->getContent());
    }

    private function historyUrl(string $profileId, string $requirementId, string $obligationKey): string
    {
        return sprintf(
            '/cabinet/compliance/person/%s/requirement/%s/obligation/history?key=%s',
            $profileId,
            $requirementId,
            rawurlencode($obligationKey),
        );
    }
}
