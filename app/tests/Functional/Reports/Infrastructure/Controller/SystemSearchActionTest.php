<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Infrastructure\Controller;

use App\Tests\Functional\Coatings\Application\UseCase\Command\Layer\CoatingSystemLayerTestFixtureTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Поиск систем по названию для формы отчёта: находит систему и отдаёт богатую карточку
 * (слои/толщина/подложка/подготовка/среда). Короткий/пустой запрос — пусто.
 */
final class SystemSearchActionTest extends WebTestCase
{
    use CoatingSystemLayerTestFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $c = $this->client->getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->setUpFixture($c, $this->em);

        $hasher = $c->get(UserPasswordHasherInterface::class);
        $user = new User(new Email('sys_search_'.uniqid('', true).'@example.com'));
        $user->setPassword('test_password', $hasher);
        (new \ReflectionProperty($user, 'isActive'))->setValue($user, true);
        (new \ReflectionProperty($user, 'roles'))->setValue($user, ['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture($this->em);
        parent::tearDown();
    }

    public function test_finds_system_by_title_with_rich_card(): void
    {
        $this->client->request('GET', '/cabinet/report/system-search', ['q' => 'Система']);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        self::assertNotEmpty($data, 'фикстурная система должна находиться по названию');
        self::assertContains((string) $this->systemId, array_column($data, 'id'));
        foreach (['substrate', 'environment', 'prep', 'dft', 'layers'] as $key) {
            self::assertArrayHasKey($key, $data[0]);
        }
    }

    public function test_empty_query_returns_empty(): void
    {
        $this->client->request('GET', '/cabinet/report/system-search');
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['data']);
    }
}
