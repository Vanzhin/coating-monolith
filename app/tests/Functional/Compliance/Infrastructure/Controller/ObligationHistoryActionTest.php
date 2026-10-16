<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Controller;

use App\Compliance\Application\UseCase\Command\AddToWriteOffDraft\AddToWriteOffDraftCommand;
use App\Compliance\Application\UseCase\Command\SaveWriteOffAct\SaveWriteOffActCommand;
use App\Compliance\Application\UseCase\Command\SignWriteOffAct\SignWriteOffActCommand;
use App\Compliance\Domain\Repository\ProfileComplianceRepositoryInterface;
use App\Compliance\Domain\Repository\WriteOffActRepositoryInterface;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
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

        $c = $this->client->getContainer();
        $bus = $c->get(CommandBusInterface::class);
        $em = $c->get(EntityManagerInterface::class);

        // Корзину наполняем с карточки выдачи (кросс-персон агрегат), затем сохраняем состав и оформляем акт.
        $bus->execute(new AddToWriteOffDraftCommand($p, $r, null));
        $em->clear();
        /** @var GetProfileQueryResult $profileResult */
        $profileResult = $c->get(QueryBusInterface::class)->execute(new GetProfileQuery($p));
        self::assertNotNull($profileResult->profile);
        $act = $c->get(WriteOffActRepositoryInterface::class)->findOpenDraft($r, $profileResult->profile->organizationId);
        self::assertNotNull($act);
        $actId = $act->getId();
        $pc = $c->get(ProfileComplianceRepositoryInterface::class)->findByProfile($p);
        self::assertNotNull($pc);
        $recordId = $pc->getRecords()[0]->getId();

        $bus->execute(new SaveWriteOffActCommand(
            $p, $actId, [['recordId' => $recordId, 'quantity' => 1.0, 'reason' => 'physical_wear']], actDate: '2026-08-10',
        ));
        $bus->execute(new SignWriteOffActCommand(
            $p, $actId, [['name' => 'Петров П. П.', 'position' => 'Инженер']], '39', '2026-08-10', $this->stageComplianceScan(),
        ));
        $em->clear();

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
     * Регрессия (прод 500): раньше obligationKey = requirementId|lower(label), и «/» в наименовании позиции
     * (напр. «Каска защитная до 1000В-1500В/50дж») утекал в ключ, ломая генерацию URL. После rekey ключ =
     * requirementId|itemId (стабильный uuid) — слэш в наименовании безопасен по построению, в ключ не попадает.
     * Проверяем: позиция со «/» в label рендерит историю без 500 (ключ — query-param, но «/» в нём уже нет).
     */
    public function test_renders_history_for_obligation_with_slash_in_label(): void
    {
        $label = 'Каска защитная до 1000В-1500В/50дж';
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance($label);
        self::assertStringNotContainsString('/', $k, 'ключ теперь uuid-основан — «/» из наименования в него не попадает');
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
