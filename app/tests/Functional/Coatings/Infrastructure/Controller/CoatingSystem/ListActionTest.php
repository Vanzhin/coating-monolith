<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\Service\CoatingSystemOperatingTemperatureCalculator;
use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Infrastructure\Cache\CoatingSystemSearchCacheRepository;
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
    /** @var list<string> */
    private array $systemIds = [];

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
        $this->em->flush();

        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        try {
            /** @var CoatingSystemSearchCacheRepository $cacheRepo */
            $cacheRepo = static::getContainer()->get(CoatingSystemSearchCacheRepository::class);
            foreach ($this->systemIds as $id) {
                $cacheRepo->delete($id);
                $system = $em->find(CoatingSystem::class, Uuid::fromString($id));
                if (null !== $system) {
                    $em->remove($system);
                }
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

    public function test_thermal_facet_inputs_rendered_when_inactive(): void
    {
        $this->persistSystem();
        $this->client->request('GET', '/cabinet/coating/coating-system/list');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('name="thermTemp"', $content);
        self::assertStringContainsString('name="thermEnv"', $content);
        self::assertStringContainsString('name="thermPeak"', $content);
    }

    public function test_thermal_filter_active_renders_reset_chip(): void
    {
        $this->persistSystem();
        $this->client->request('GET', '/cabinet/coating/coating-system/list?thermTemp=100&thermEnv=dry_heat&thermPeak=1');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Работает при', (string) $this->client->getResponse()->getContent());
    }

    private function persistSystem(string $suffix = ''): CoatingSystem
    {
        if ('' === $suffix) {
            $suffix = uniqid('', true);
        }
        $treatment = $this->createAndPersistTreatment($this->em, $suffix);
        $system = new CoatingSystem(
            Uuid::v7(),
            'Список-система-'.$suffix,
            'Описание для теста листинга',
            Substrate::CONCRETE,
            $treatment,
        );
        $this->em->persist($system);
        $this->em->flush();
        $this->systemIds[] = $system->getId();

        /** @var CoatingSystemSearchCacheRepository $cacheRepo */
        $cacheRepo = static::getContainer()->get(CoatingSystemSearchCacheRepository::class);
        $temperatureCalculator = static::getContainer()->get(CoatingSystemOperatingTemperatureCalculator::class);
        $cacheRepo->upsert($system, $temperatureCalculator->calculate($system));

        return $system;
    }

    public function test_get_shows_systems(): void
    {
        $this->persistSystem();
        $this->client->request('GET', '/cabinet/coating/coating-system/list');

        self::assertResponseIsSuccessful();
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Системы покрытий', $content);
        self::assertStringContainsString('Список-система-', $content);
    }

    public function test_returns_full_page_when_no_partial(): void
    {
        $sys = $this->persistSystem();
        $this->client->request('GET', '/cabinet/coating/coating-system/list');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($sys->getTitle(), (string) $this->client->getResponse()->getContent());
    }

    public function test_q_filters_results(): void
    {
        $suffix = uniqid('', true);
        $sysA = $this->persistSystem('AlphaUniq'.$suffix);
        $sysB = $this->persistSystem('BetaUniq'.$suffix);

        $this->client->request('GET', '/cabinet/coating/coating-system/list?q=AlphaUniq'.$suffix);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('data-system-id="'.$sysA->getId().'"', $content);
        self::assertStringNotContainsString('data-system-id="'.$sysB->getId().'"', $content);
    }

    public function test_partial_fragment_returns_only_cards(): void
    {
        $this->persistSystem();
        $this->client->request('GET', '/cabinet/coating/coating-system/list?partial=1');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('<html', $body);
    }

    public function test_list_uses_cards_not_table(): void
    {
        $this->persistSystem();
        $this->client->request('GET', '/cabinet/coating/coating-system/list');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('class="coating-card', $content);
        self::assertStringNotContainsString('<table class="table table-hover', $content);
    }

    public function test_sp_standard_renders_sp_cascade_not_iso(): void
    {
        // Каскад фильтра ветвится по стандарту: при СП показываем оси СП (среда/условия),
        // а не ISO-категории/долговечность.
        $this->client->request('GET', '/cabinet/coating/coating-system/list?standard=SP_28&category=weak');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Условия эксплуатации', $content);
        self::assertStringContainsString('Среднеагрессивная', $content);
        self::assertStringContainsString('В помещении', $content);
        self::assertStringNotContainsString('C1 — Очень низкая', $content);
        self::assertStringNotContainsString('менее 7 лет', $content);
    }

    public function test_list_shows_treatment_code_on_card(): void
    {
        $suffix = uniqid('', true);
        $this->persistSystem($suffix);
        $this->client->request('GET', '/cabinet/coating/coating-system/list?q=Список-система-'.$suffix);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('ST-', $content);
    }

    public function test_list_shows_layer_count_and_total_dft_on_card(): void
    {
        $suffix = uniqid('', true);
        $this->persistSystem($suffix);
        $this->client->request('GET', '/cabinet/coating/coating-system/list?q=Список-система-'.$suffix);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('bi-layers', $content);
        self::assertStringContainsString('мкм', $content);
    }

    public function test_list_cards_are_lazy_preview_triggers(): void
    {
        $this->persistSystem();
        $this->client->request('GET', '/cabinet/coating/coating-system/list');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        // Карточки — ленивые триггеры фрагмента превью; инлайн-модалки больше нет.
        self::assertStringContainsString('click->entity-preview#open', $content);
        self::assertStringNotContainsString('id="coatingSystemModal"', $content);
    }

    public function test_list_wires_lazy_system_preview_endpoint(): void
    {
        $suffix = uniqid('', true);
        $this->persistSystem($suffix);
        $this->client->request('GET', '/cabinet/coating/coating-system/list?q=Список-система-'.$suffix);

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('class="coating-card', $content);
        self::assertStringContainsString('data-entity-preview-endpoint-value', $content);
    }

    public function test_inverted_range_does_not_break(): void
    {
        $this->persistSystem();
        // Инвертированный диапазон: from > to. Должны вернуть 200, игнорируя невалидный фильтр.
        $this->client->request('GET', '/cabinet/coating/coating-system/list?applicationMinTempFrom=100&applicationMinTempTo=10');

        self::assertResponseIsSuccessful();
    }
}
