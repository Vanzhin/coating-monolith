<?php

declare(strict_types=1);

namespace App\Tests\Functional\Users\Infrastructure\Controller\User;

use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Typeahead по email для фасета «Актор» админ-журнала. Доступ закрыт для
 * не-админов (SearchUsersQueryHandler → AuditAccessControl), как и сам журнал.
 */
final class SuggestActionTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $adminEmail;
    private string $regularEmail;
    private string $targetEmail;
    private string $targetUlid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->adminEmail = 'test_user_suggest_admin_'.$suffix.'@example.com';
        $this->regularEmail = 'test_user_suggest_regular_'.$suffix.'@example.com';
        $this->targetEmail = 'suggestusertarget_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);

        $admin = new User(new Email($this->adminEmail));
        $admin->setPassword('test_password', $hasher);
        $this->activate($admin);
        $this->grantAdmin($admin);
        $this->em->persist($admin);

        $regular = new User(new Email($this->regularEmail));
        $regular->setPassword('test_password', $hasher);
        $this->activate($regular);
        $this->em->persist($regular);

        $target = new User(new Email($this->targetEmail));
        $target->setPassword('test_password', $hasher);
        $this->activate($target);
        $this->em->persist($target);

        $this->em->flush();

        $this->targetUlid = $target->getUlid();

        $this->client->loginUser($admin);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            foreach ([$this->adminEmail, $this->regularEmail, $this->targetEmail] as $email) {
                $user = $em->getRepository(User::class)->findOneBy(['email.value' => $email]);
                if (null !== $user) {
                    $em->remove($user);
                }
            }

            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_admin_finds_user_by_email_substring(): void
    {
        $this->client->request('GET', '/cabinet/users/suggest', [
            'q' => 'suggestusertarget_',
        ]);

        self::assertResponseIsSuccessful();

        $items = $this->items();
        self::assertNotEmpty($items);

        $ids = array_column($items, 'id');
        self::assertContains($this->targetUlid, $ids);

        $byId = array_combine($ids, array_column($items, 'title'));
        self::assertSame($this->targetEmail, $byId[$this->targetUlid]);
    }

    public function test_empty_q_returns_empty_items(): void
    {
        $this->client->request('GET', '/cabinet/users/suggest', ['q' => '']);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->items());
    }

    public function test_non_admin_gets_403(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $regular = $em->getRepository(User::class)->findOneBy(['email.value' => $this->regularEmail]);
        $this->client->loginUser($regular);

        $this->client->request('GET', '/cabinet/users/suggest', ['q' => 'suggestusertarget_']);

        self::assertResponseStatusCodeSame(403);
    }

    private function activate(User $user): void
    {
        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);
    }

    private function grantAdmin(User $user): void
    {
        $ref = new \ReflectionProperty($user, 'roles');
        $ref->setAccessible(true);
        $ref->setValue($user, ['ROLE_ADMIN']);
    }

    /**
     * @return list<array{id: string, title: string}>
     */
    private function items(): array
    {
        $response = json_decode($this->client->getResponse()->getContent(), true);

        // ResponseListener оборачивает JsonResponse в {result, status, data, message}.
        return $response['data']['items'] ?? $response['items'] ?? [];
    }
}
