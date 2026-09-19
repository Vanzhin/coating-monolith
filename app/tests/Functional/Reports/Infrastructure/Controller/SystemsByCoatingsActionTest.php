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
 * Подбор систем по покрытиям: система, содержащая покрытие, находится по его id и несёт
 * подложку/среду; без покрытий — пусто.
 */
final class SystemsByCoatingsActionTest extends WebTestCase
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
        $user = new User(new Email('sys_by_coat_'.uniqid('', true).'@example.com'));
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

    public function test_returns_systems_containing_coating(): void
    {
        $this->client->request('GET', '/cabinet/report/systems-by-coatings', ['coatingIds' => [(string) $this->coatingId]]);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        self::assertNotEmpty($data);
        self::assertContains((string) $this->systemId, array_column($data, 'id'));
        self::assertArrayHasKey('substrate', $data[0]);
        self::assertArrayHasKey('environment', $data[0]);
    }

    public function test_empty_without_coatings(): void
    {
        $this->client->request('GET', '/cabinet/report/systems-by-coatings');
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['data']);
    }
}
