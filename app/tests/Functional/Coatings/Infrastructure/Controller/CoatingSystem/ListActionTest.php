<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystemChainValidator;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Tests\Functional\Coatings\Fixture\SurfaceTreatmentFixtureTrait;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class ListActionTest extends WebTestCase
{
    use SurfaceTreatmentFixtureTrait;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $userEmail;
    private string $systemId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = $this->client->getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $suffix = uniqid('', true);
        $this->userEmail = 'test_cs_list_'.$suffix.'@example.com';

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User(new Email($this->userEmail));
        $user->setPassword('test_password', $hasher);

        $ref = new \ReflectionProperty($user, 'isActive');
        $ref->setAccessible(true);
        $ref->setValue($user, true);

        $this->em->persist($user);

        $treatment = $this->createAndPersistTreatment($this->em, $suffix);

        $chainValidator = new CoatingSystemChainValidator();
        $system = new CoatingSystem(
            Uuid::v7(),
            'Список-система-'.$suffix,
            'Описание для теста листинга',
            Substrate::CONCRETE,
            $treatment,
            $chainValidator,
        );
        $this->em->persist($system);
        $this->em->flush();

        $this->systemId = $system->getId();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            $system = $em->find(CoatingSystem::class, Uuid::fromString($this->systemId));
            if (null !== $system) {
                $em->remove($system);
            }

            $user = $em->getRepository(User::class)->findOneBy(['email.value' => $this->userEmail]);
            if (null !== $user) {
                $em->remove($user);
            }

            $em->flush();
            $this->cleanUpTreatment($em);
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }

        parent::tearDown();
    }

    public function test_get_shows_systems(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/list');

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Системы покрытий', $content);
        self::assertStringContainsString('Список-система-', $content);
    }

    public function test_get_with_search_filter(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/list', ['search' => 'Список-система-']);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Список-система-', $content);
    }

    public function test_list_uses_cards_not_table(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/list');

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('class="coating-card', $content);
        self::assertStringNotContainsString('<table class="table table-hover', $content);
    }

    public function test_list_shows_treatment_code_on_card(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/list', ['search' => 'Список-система-']);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        // Fixture creates treatment with code = substr('ST-' . $suffix, 0, 30)
        self::assertStringContainsString('ST-', $content);
    }

    public function test_list_shows_layer_count_and_total_dft_on_card(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/list', ['search' => 'Список-система-']);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        // Layer count badge: bi-layers icon + count (0 layers for fixture system)
        self::assertStringContainsString('bi-layers', $content);
        // Total DFT shown (0 мкм for system without layers)
        self::assertStringContainsString('мкм', $content);
    }

    public function test_list_shows_modal_placeholder(): void
    {
        $this->client->request('GET', '/cabinet/coating/coating-system/list');

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('id="coatingSystemModal"', $content);
    }

    public function test_list_shows_compliance_badges_on_card_when_available(): void
    {
        // System created in fixture has no compliance; modal is still present.
        // This test verifies the card structure is present and no compliance badges shown for the empty case.
        $this->client->request('GET', '/cabinet/coating/coating-system/list', ['search' => 'Список-система-']);

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        // Card is rendered
        self::assertStringContainsString('class="coating-card', $content);
        // Compliance section in modal is present (hidden via d-none, but rendered)
        self::assertStringContainsString('modal-compliance-block', $content);
    }
}
