<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\Coating;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ListActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_coating_list_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->userEmail]);
            if (null !== $user) {
                $em->remove($user);
            }

            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_list_renders_ok(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/list');

        self::assertResponseIsSuccessful();
    }

    public function test_list_with_facets_renders_ok(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/list?minRecoat20From=2&baseValues[]=EP&sort=nonsense');

        self::assertResponseIsSuccessful();
    }

    public function test_inverted_range_shows_error_not_500(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/list?appMinTempFrom=10&appMinTempTo=5');

        self::assertResponseIsSuccessful(); // ошибка рендерится в форме, не 500
    }

    public function test_partial_returns_cards_batch(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating/list?partial=1');

        self::assertResponseIsSuccessful();
    }
}
