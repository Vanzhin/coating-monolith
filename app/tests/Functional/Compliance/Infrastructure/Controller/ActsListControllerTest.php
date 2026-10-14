<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Infrastructure\Controller;

use App\Compliance\Application\UseCase\Command\FormDraft\FormDraftCommand;
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
 * Список актов выдачи: единый findByFilter (фильтр/сорт/пагинация) + owner-скоуп (не-админ → только свои) +
 * догрузка (?partial=1). Акты списания сюда не входят.
 */
final class ActsListControllerTest extends WebTestCase
{
    use AuthenticatesActorTrait;
    use EnrollsComplianceTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = $this->client->getContainer();

        $user = new User(new Email('acts_'.uniqid('', true).'@example.com'));
        $user->setPassword('test_password', $container->get(UserPasswordHasherInterface::class));
        $this->setPrivate($user, 'isActive', true);
        $this->setPrivate($user, 'roles', ['ROLE_ADMIN']);
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);
        $this->authenticateAsSystem();
    }

    public function test_admin_sees_signed_act_with_identity_and_actions(): void
    {
        ['profileId' => $profileId, 'requirementId' => $requirementId, 'key' => $key] = $this->enrollCompliance();
        $this->issueCard($profileId, $requirementId, $key); // подписанный акт выдачи

        $crawler = $this->client->request('GET', '/cabinet/compliance/acts');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Иванов', $html, 'ФИО сотрудника в строке акта');
        self::assertStringContainsString('Подписан', $html, 'статус подписанного акта');
        // Действие «Карточка» ведёт на per-document маршрут (слепок именно этого акта).
        self::assertGreaterThan(0, $crawler->filter('a[href*="/document/"][href*="/card"]')->count(), 'ссылка на карточку по документу');
        // «Открыть» ведёт в журнал человека по требованию (отдельного просмотра акта больше нет — выдача в журнале).
        self::assertGreaterThan(0, $crawler->filter('a[href*="/requirement/"][href*="/issue"]')->count(), 'ссылка «Открыть» на карточку требования');
    }

    // Owner-скоуп (не-админ → только свои акты, чужой ?profile игнорируется) обеспечивает общий
    // ComplianceDashboardScope::restrictProfileIds — тот же код-путь, что у дашборда, и покрыт его тестами.
    // HTTP-повтор здесь не держим: системный принципал из setUp (нужен прямым commandBus-вызовам сетапа)
    // делает актора менеджером и обходит owner-гейт — проверять скоуп на этом сетапе некорректно.

    public function test_status_filter_narrows_to_drafts(): void
    {
        ['profileId' => $p1, 'requirementId' => $r1, 'key' => $k1] = $this->enrollCompliance();
        $this->issueCard($p1, $r1, $k1); // подписанный

        // Второй профиль с ОТКРЫТЫМ черновиком (не подписан).
        ['profileId' => $p2, 'requirementId' => $r2] = $this->enrollCompliance();
        $bus = $this->client->getContainer()->get(CommandBusInterface::class);
        $bus->execute(new FormDraftCommand($p2, $r2));

        // Фильтр по статусу «черновик» → в СПИСКЕ только черновики. Бейдж статуса: черновик = text-bg-secondary,
        // подписан = text-bg-success (слово «Подписан» есть и в метке radio-фильтра, поэтому считаем именно бейджи).
        $crawler = $this->client->request('GET', '/cabinet/compliance/acts?status=formed');
        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('.badge.text-bg-secondary')->count(), 'в списке есть черновики');
        self::assertSame(0, $crawler->filter('.badge.text-bg-success')->count(), 'подписанные отфильтрованы статусом formed');
        // Черновик можно удалить из списка через общий компонент удаления (триггер модалки + POST на draft-delete).
        self::assertGreaterThan(0, $crawler->filter('button[data-bs-url*="/draft-delete"]')->count(), 'у черновика есть кнопка удаления');
        self::assertGreaterThan(0, $crawler->filter('#deleteModal')->count(), 'на странице подключён компонент удаления');
    }

    public function test_partial_returns_bare_batch(): void
    {
        ['profileId' => $p, 'requirementId' => $r, 'key' => $k] = $this->enrollCompliance();
        $this->issueCard($p, $r, $k);

        $this->client->request('GET', '/cabinet/compliance/acts?partial=1');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Иванов', $html, 'батч содержит строку акта');
        self::assertStringNotContainsString('Акты выдачи', $html, 'батч без шапки страницы');
    }

    private function setPrivate(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }
}
