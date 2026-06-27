<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\CoatingTag;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingTagSpecification;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class CreateGeneralTagActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    /** @var list<string> */
    private array $titlesToCleanup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'create_tag_' . $suffix . '@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);
        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);
        $rolesRef = new \ReflectionProperty($user, 'roles');
        $rolesRef->setAccessible(true);
        $rolesRef->setValue($user, ['ROLE_ADMIN']);

        $this->em->persist($user);
        $this->em->flush();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            $repo = static::getContainer()->get(CoatingTagRepositoryInterface::class);
            foreach ($this->titlesToCleanup as $title) {
                $tag = $repo->findOneByTitleAndType($title, CoatingTag::TYPE_GENERAL);
                if ($tag !== null) {
                    $em->remove($tag);
                }
            }
            $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->userEmail]);
            if ($user !== null) {
                $em->remove($user);
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, "tearDown cleanup error: " . $e->getMessage() . "\n");
        }
        parent::tearDown();
    }

    public function testCreatesNewGeneralTag(): void
    {
        $title = 'Для бетона unique-' . uniqid('', false);
        $this->titlesToCleanup[] = $title;

        $this->client->request(
            'POST',
            '/cabinet/coating/coating-tag',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => $title]),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        // ResponseListener wraps response in envelope: {result, status, data, message}
        self::assertArrayHasKey('data', $payload);
        $data = $payload['data'];
        self::assertArrayHasKey('id', $data);
        self::assertSame($title, $data['title']);

        $repo = static::getContainer()->get(CoatingTagRepositoryInterface::class);
        $tag = $repo->findOneByTitleAndType($title, CoatingTag::TYPE_GENERAL);
        self::assertNotNull($tag);
        self::assertSame(CoatingTag::TYPE_GENERAL, $tag->getType());
    }

    public function testRejectsDuplicate(): void
    {
        $title = 'Дубль-' . uniqid('', false);
        $this->titlesToCleanup[] = $title;

        // Первый раз — успешно.
        $this->client->request(
            'POST',
            '/cabinet/coating/coating-tag',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => $title]),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // Второй раз — 422.
        $this->client->request(
            'POST',
            '/cabinet/coating/coating-tag',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => $title]),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $payload = json_decode($this->client->getResponse()->getContent(), true);
        // ResponseListener wraps error in envelope: {result: 'error', status: 422, data: null, message: '...'}
        self::assertArrayHasKey('message', $payload);
        self::assertStringContainsString('уже существует', $payload['message']);
    }

    public function testRejectsEmptyTitle(): void
    {
        $this->client->request(
            'POST',
            '/cabinet/coating/coating-tag',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['title' => '   ']),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
