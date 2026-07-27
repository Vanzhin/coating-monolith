<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Api\SurfaceTreatment;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Aggregate\SurfaceTreatment\SurfaceTreatment;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ListApiActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $userPassword;
    private string $suffix;

    /** @var list<Uuid> */
    private array $treatmentIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->suffix = bin2hex(random_bytes(4));
        $this->userEmail = 'test_st_api_list_'.$this->suffix.'@example.com';
        $this->userPassword = 'test_api_password_'.$this->suffix;

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword($this->userPassword, $hasher);
        (new \ReflectionProperty(User::class, 'isActive'))->setValue($user, true);
        $this->em->persist($user);

        $t1 = new SurfaceTreatment(
            Uuid::v7(),
            'Дробеструйная очистка Sa 2½ list-test-'.$this->suffix,
            'Sa2½',
            'ISO 8501-1',
            [Substrate::STEEL_CARBON],
        );
        $this->em->persist($t1);
        $this->treatmentIds[] = $t1->id;

        $t2 = new SurfaceTreatment(
            Uuid::v7(),
            'Механическая очистка list-test-'.$this->suffix,
            null,
            null,
            [Substrate::STEEL_GALVANIZED],
        );
        $this->em->persist($t2);
        $this->treatmentIds[] = $t2->id;

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $em = $this->client->getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ($this->treatmentIds as $id) {
                $t = $em->find(SurfaceTreatment::class, $id);
                if (null !== $t) {
                    $em->remove($t);
                }
            }

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

    private function loginAndGetToken(): string
    {
        $this->client->request(
            'POST',
            '/api/auth/token/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $this->userEmail, 'password' => $this->userPassword]),
        );

        $response = json_decode($this->client->getResponse()->getContent(), true);

        return $response['data']['token'] ?? '';
    }

    public function test_get_without_filter_returns_200_with_items(): void
    {
        $token = $this->loginAndGetToken();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        $this->client->request('GET', '/api/surface-treatments');

        self::assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $body['result']);
        self::assertArrayHasKey('items', $body['data']);
        self::assertIsArray($body['data']['items']);

        $ids = array_column($body['data']['items'], 'id');
        foreach ($this->treatmentIds as $uuid) {
            self::assertContains($uuid->toRfc4122(), $ids);
        }
    }

    public function test_get_with_valid_substrate_filter_returns_matching_items(): void
    {
        $token = $this->loginAndGetToken();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        $this->client->request('GET', '/api/surface-treatments', ['substrate' => 'steel_carbon']);

        self::assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $body['result']);

        $items = $body['data']['items'];
        self::assertNotEmpty($items);

        $ids = array_column($items, 'id');
        self::assertContains($this->treatmentIds[0]->toRfc4122(), $ids);
    }

    public function test_get_with_invalid_substrate_returns_400(): void
    {
        $token = $this->loginAndGetToken();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        $this->client->request('GET', '/api/surface-treatments', ['substrate' => 'INVALID_SUBSTRATE']);

        self::assertResponseStatusCodeSame(400);
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('error', $body['result']);
    }

    public function test_get_with_q_filter_returns_matching_items(): void
    {
        $token = $this->loginAndGetToken();
        $this->client->setServerParameter('HTTP_AUTHORIZATION', 'Bearer '.$token);

        $this->client->request('GET', '/api/surface-treatments', ['q' => 'Дробеструйная']);

        self::assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        self::assertSame('success', $body['result']);
        self::assertNotEmpty($body['data']['items']);
    }
}
