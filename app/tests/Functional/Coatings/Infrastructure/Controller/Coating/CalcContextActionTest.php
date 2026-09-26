<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\Coating;

use App\Tests\Functional\Coatings\Application\UseCase\Command\Layer\CoatingSystemLayerTestFixtureTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Контекст покрытия для калькуляторов по id: кормит значки калькуляторов у полей слоёв на странице
 * заполнения (ленивый засев из покрытия). Отдаёт сухой остаток и пр. по id; несуществующее — 404.
 */
final class CalcContextActionTest extends WebTestCase
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
        $user = new User(new Email('calc_ctx_'.uniqid('', true).'@example.com'));
        $user->setPassword('test_password', $hasher);
        $this->setPrivate($user, 'isActive', true);
        $this->setPrivate($user, 'roles', ['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture($this->em);
        parent::tearDown();
    }

    public function test_returns_calc_context_by_id(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/'.$this->coatingId.'/calc-context');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('volumeSolid', $body); // сухой остаток для калькулятора плёнки
    }

    public function test_unknown_coating_is_404(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/00000000-0000-0000-0000-000000000000/calc-context');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    private function setPrivate(object $obj, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($obj, $prop);
        $ref->setValue($obj, $value);
    }
}
