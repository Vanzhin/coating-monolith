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
 * Гидрация чипов фасета «Актор» админ-журнала по списку ulid. Доступ закрыт
 * для не-админов (GetUsersByIdsQueryHandler → AuditAccessControl).
 */
final class ByIdsActionTest extends WebTestCase
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
        $this->adminEmail = 'test_user_byids_admin_'.$suffix.'@example.com';
        $this->regularEmail = 'test_user_byids_regular_'.$suffix.'@example.com';
        $this->targetEmail = 'byidsusertarget_'.$suffix.'@example.com';

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

    public function test_returns_id_and_title_by_ids(): void
    {
        $this->client->request('GET', '/cabinet/users/by-ids', [
            'ids' => [$this->targetUlid],
        ]);

        self::assertResponseIsSuccessful();

        $items = $this->items();
        self::assertCount(1, $items);
        self::assertSame($this->targetUlid, $items[0]['id']);
        self::assertSame($this->targetEmail, $items[0]['title']);
        self::assertSame(['id', 'title'], array_keys($items[0]));
    }

    public function test_empty_ids_returns_empty_items(): void
    {
        $this->client->request('GET', '/cabinet/users/by-ids');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->items());
    }

    public function test_invalid_id_is_ignored(): void
    {
        $this->client->request('GET', '/cabinet/users/by-ids', [
            'ids' => ['not-a-ulid', $this->targetUlid],
        ]);

        self::assertResponseIsSuccessful();

        $items = $this->items();
        self::assertCount(1, $items);
        self::assertSame($this->targetUlid, $items[0]['id']);
    }

    public function test_non_admin_gets_403(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $regular = $em->getRepository(User::class)->findOneBy(['email.value' => $this->regularEmail]);
        $this->client->loginUser($regular);

        $this->client->request('GET', '/cabinet/users/by-ids', [
            'ids' => [$this->targetUlid],
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    private function activate(User $user): void
    {
        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setValue($user, true);
    }

    private function grantAdmin(User $user): void
    {
        $ref = new \ReflectionProperty($user, 'roles');
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
