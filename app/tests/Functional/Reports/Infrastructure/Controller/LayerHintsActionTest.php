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
 * Инлайн-подсказки слоя: тонкая плёнка (ниже минимума покрытия) даёт предупреждение; без покрытия — пусто.
 */
final class LayerHintsActionTest extends WebTestCase
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
        $user = new User(new Email('layer_hints_'.uniqid('', true).'@example.com'));
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

    public function test_thin_film_warns(): void
    {
        // Покрытие фикстуры: DFT 60–200 мкм. Ставим 40 → ниже минимума.
        $this->client->request('GET', '/cabinet/report/layer-hints', ['coatingId' => (string) $this->coatingId, 'dryFilmMean' => '40']);
        self::assertResponseIsSuccessful();

        $data = json_decode((string) $this->client->getResponse()->getContent(), true)['data'];
        self::assertContains('dft_below_min', array_column($data, 'code'));
    }

    public function test_no_coating_empty(): void
    {
        $this->client->request('GET', '/cabinet/report/layer-hints');
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode((string) $this->client->getResponse()->getContent(), true)['data']);
    }
}
