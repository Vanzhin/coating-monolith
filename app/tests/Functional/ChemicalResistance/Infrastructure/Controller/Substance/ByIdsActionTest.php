<?php

declare(strict_types=1);

namespace App\Tests\Functional\ChemicalResistance\Infrastructure\Controller\Substance;

use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Infrastructure\Repository\SubstanceRepository;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Зеркало Coatings/Infrastructure/Controller/Coating/ByIdsActionTest — резолв
 * id → {id, title} для гидрации чипов фасета «Вещества» из shareable-ссылки.
 */
final class ByIdsActionTest extends WebTestCase
{
    private const URL = '/cabinet/chemical-resistance/substance/by-ids';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $substanceId;
    private string $substanceName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_substance_byids_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        // Обычный пользователь без ROLE_ADMIN — эндпоинт должен быть доступен всем в кабинете.
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setValue($user, true);

        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        /** @var SubstanceRepository $repo */
        $repo = $container->get(SubstanceRepository::class);
        /** @var SubstanceSpecification $spec */
        $spec = $container->get(SubstanceSpecification::class);

        $this->substanceName = 'Этанол_'.$suffix;
        $substance = new Substance(Uuid::v4(), $this->substanceName, null, new StringCollection(), $spec);
        $repo->add($substance);
        $this->em->flush();

        $this->substanceId = $substance->getId();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            $substance = $em->find(Substance::class, Uuid::fromString($this->substanceId));
            if (null !== $substance) {
                $em->remove($substance);
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

    public function test_returns_id_and_title_by_ids(): void
    {
        $this->client->request('GET', self::URL, [
            'ids' => [$this->substanceId],
        ]);

        self::assertResponseIsSuccessful();

        $items = $this->items();
        self::assertCount(1, $items);
        self::assertSame($this->substanceId, $items[0]['id']);
        self::assertSame($this->substanceName, $items[0]['title']);
        self::assertSame(['id', 'title'], array_keys($items[0]));
    }

    public function test_empty_ids_returns_empty_items(): void
    {
        $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->items());
    }

    public function test_invalid_id_is_ignored(): void
    {
        $this->client->request('GET', self::URL, [
            'ids' => ['not-a-uuid', $this->substanceId],
        ]);

        self::assertResponseIsSuccessful();

        $items = $this->items();
        self::assertCount(1, $items);
        self::assertSame($this->substanceId, $items[0]['id']);
    }

    /**
     * @return list<array{id: string, title: string}>
     */
    private function items(): array
    {
        $response = json_decode((string) $this->client->getResponse()->getContent(), true);

        // ResponseListener оборачивает JsonResponse в {result, status, data, message}.
        return $response['data']['items'] ?? $response['items'] ?? [];
    }
}
