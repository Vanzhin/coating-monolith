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

final class SuggestTagsActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    /** @var list<string> */
    private array $createdTagIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'suggest_tags_' . $suffix . '@example.com';

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
            foreach ($this->createdTagIds as $id) {
                $tag = $repo->findOneById($id);
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

    public function testReturnsGeneralTagsByPrefix(): void
    {
        $this->makeTag('Для бетона test', CoatingTag::TYPE_GENERAL);
        $this->makeTag('Для стали test', CoatingTag::TYPE_GENERAL);

        $this->client->request('GET', '/cabinet/coating/coating-tag/suggest?q=для&type=general');

        self::assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertArrayHasKey('data', $response);
        $payload = $response['data'];
        self::assertIsArray($payload);
        $titles = array_column($payload, 'title');
        self::assertContains('Для бетона test', $titles);
        self::assertContains('Для стали test', $titles);
    }

    public function testEmptyQueryReturnsEmptyArray(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-tag/suggest?q=&type=general');

        self::assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertArrayHasKey('data', $response);
        self::assertSame([], $response['data']);
    }

    public function testEachItemHasIdAndTitle(): void
    {
        $tag = $this->makeTag('Уникальный тег xyz', CoatingTag::TYPE_GENERAL);

        $this->client->request('GET', '/cabinet/coating/coating-tag/suggest?q=уникальный&type=general');

        self::assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true);
        self::assertIsArray($response);
        self::assertArrayHasKey('data', $response);
        $payload = $response['data'];
        self::assertNotEmpty($payload);
        $first = $payload[0];
        self::assertArrayHasKey('id', $first);
        self::assertArrayHasKey('title', $first);
    }

    private function makeTag(string $title, ?string $type): CoatingTag
    {
        $container = $this->client->getContainer();
        $spec = $container->get(CoatingTagSpecification::class);
        $tag = new CoatingTag($title, $spec, $type);
        $container->get(CoatingTagRepositoryInterface::class)->add($tag);
        $this->createdTagIds[] = $tag->getId();
        return $tag;
    }
}
