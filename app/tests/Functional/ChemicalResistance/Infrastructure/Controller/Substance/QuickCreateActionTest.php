<?php

declare(strict_types=1);

namespace App\Tests\Functional\ChemicalResistance\Infrastructure\Controller\Substance;

use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer;
use App\ChemicalResistance\Infrastructure\Repository\SubstanceRepository;
use App\Tests\Support\CsrfTestHelper;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Инлайн-создание вещества под СЕССИЕЙ кабинета (JSON). Раньше автокомплиты били
 * в /api/... (stateless JWT) и с сессионной кукой получали 401 — «создать не
 * срабатывает». Этот эндпоинт живёт под сессионным фаерволом.
 */
final class QuickCreateActionTest extends WebTestCase
{
    private const URL = '/cabinet/chemical-resistance/substance/quick-create';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private SubstanceRepository $substances;
    private User $admin;
    private User $regular;
    private string $adminEmail;
    private string $userEmail;
    private string $substanceName;
    /** @var list<string> */
    private array $createdSubstanceNames = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $c = $this->client->getContainer();
        $this->em = $c->get(EntityManagerInterface::class);
        $this->substances = $c->get(SubstanceRepository::class);

        $suffix = uniqid('', true);
        $hasher = $c->get(UserPasswordHasherInterface::class);
        $this->adminEmail = 'qc_admin_'.$suffix.'@example.com';
        $this->userEmail = 'qc_user_'.$suffix.'@example.com';
        $this->admin = $this->makeUser($this->adminEmail, ['ROLE_ADMIN'], $hasher);
        $this->regular = $this->makeUser($this->userEmail, ['ROLE_USER'], $hasher);
        $this->em->flush();

        $this->substanceName = 'Вещество-quick-'.$suffix;
    }

    /** @param list<string> $roles */
    private function makeUser(string $email, array $roles, UserPasswordHasherInterface $hasher): User
    {
        $user = new User(new Email($email));
        $user->setPassword('test_password', $hasher);
        $a = new \ReflectionProperty($user, 'isActive');
        $a->setAccessible(true);
        $a->setValue($user, true);
        $r = new \ReflectionProperty($user, 'roles');
        $r->setAccessible(true);
        $r->setValue($user, $roles);
        $this->em->persist($user);

        return $user;
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        try {
            $repo = static::getContainer()->get(SubstanceRepository::class);
            foreach ($this->createdSubstanceNames as $name) {
                $s = $repo->findByCanonicalNameKey(SubstanceNameNormalizer::normalize($name));
                if (null !== $s) {
                    $managed = $em->find(Substance::class, Uuid::fromString($s->getId()));
                    if (null !== $managed) {
                        $em->remove($managed);
                    }
                }
            }
            $em->flush();
            foreach ([$this->adminEmail, $this->userEmail] as $email) {
                $u = $em->getRepository(User::class)->findOneBy(['email.value' => $email]);
                if (null !== $u) {
                    $em->remove($u);
                }
            }
            $em->flush();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_admin_creates_substance_under_session(): void
    {
        $this->createdSubstanceNames[] = $this->substanceName;
        $this->client->loginUser($this->admin);
        CsrfTestHelper::enable($this->client);

        $this->client->request(
            'POST',
            self::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['canonicalName' => $this->substanceName], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(201);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true);
        $data = $body['data'] ?? $body;
        self::assertNotEmpty($data['id'] ?? null, 'Ответ содержит id созданного вещества.');
        self::assertSame($this->substanceName, $data['canonicalName'] ?? null);
    }

    public function test_non_admin_cannot_create(): void
    {
        $name = $this->substanceName.'-forbidden';
        $this->createdSubstanceNames[] = $name;
        $this->client->loginUser($this->regular);
        CsrfTestHelper::enable($this->client);

        $this->client->request(
            'POST',
            self::URL,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['canonicalName' => $name], JSON_THROW_ON_ERROR),
        );

        $this->em->clear();
        self::assertNull(
            $this->substances->findByCanonicalNameKey(SubstanceNameNormalizer::normalize($name)),
            'Не-админ не создаёт вещество — гейт в команде.',
        );
    }
}
