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
 * Эндпоинт автокомплита веществ делегирует в единый SubstanceRepository::findByFilter
 * (Task 1) — опечаткоустойчивый триграммный fallback — и отдаёт поле `title` (=
 * canonicalName) для будущих чипов фасета. Shape остаётся bare-array, старые поля
 * (id/canonicalName/cas) целы — страница «по веществу» (substance-multiselect) не ломается.
 */
final class SubstanceAutocompleteActionTest extends WebTestCase
{
    private const URL = '/cabinet/chemical-resistance/substance/autocomplete';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $c = $this->client->getContainer();
        $this->em = $c->get(EntityManagerInterface::class);

        $hasher = $c->get(UserPasswordHasherInterface::class);
        $user = new User(new Email('autocomplete_user_'.uniqid('', true).'@example.com'));
        $user->setPassword('test_password', $hasher);
        $active = new \ReflectionProperty($user, 'isActive');
        $active->setValue($user, true);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($user);

        $repo = $c->get(SubstanceRepository::class);
        $spec = $c->get(SubstanceSpecification::class);
        $repo->add(new Substance(Uuid::v4(), 'Этанол', null, new StringCollection(), $spec));
        $this->em->clear();
    }

    public function test_autocomplete_includes_title_and_is_fuzzy(): void
    {
        $this->client->request('GET', self::URL.'?q='.rawurlencode('этонол'));

        self::assertResponseIsSuccessful();
        $items = $this->decodeItems();

        self::assertNotEmpty($items, 'Опечатка «этонол» должна находить «Этанол» через fuzzy fallback.');
        self::assertSame('Этанол', $items[0]['title'] ?? null, 'Поле title присутствует и равно canonicalName.');
        self::assertSame('Этанол', $items[0]['canonicalName'] ?? null, 'Старое поле canonicalName цело.');
        self::assertArrayHasKey('id', $items[0]);
        self::assertArrayHasKey('cas', $items[0]);
    }

    public function test_autocomplete_matches_substring_not_just_prefix(): void
    {
        // «тано» — середина слова «Этанол», prefix-ILIKE (старое поведение) такое
        // не находил, substring через findByFilter — находит.
        $this->client->request('GET', self::URL.'?q='.rawurlencode('тано'));

        self::assertResponseIsSuccessful();
        $items = $this->decodeItems();

        self::assertNotEmpty($items, 'Подстрока в середине названия должна находиться.');
        self::assertSame('Этанол', $items[0]['canonicalName']);
        self::assertSame('Этанол', $items[0]['title']);
    }

    /**
     * Глобальный ResponseListener оборачивает JSON-ответы в конверт
     * {result,status,data,message} — сам Action отдаёт голый массив (см. JS
     * substance_multiselect_controller.js: `raw?.data ?? raw`), конверт навешивается
     * снаружи. Тест читает финальный HTTP-ответ, поэтому разворачивает `data`.
     *
     * @return list<array<string, mixed>>
     */
    private function decodeItems(): array
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);

        return $decoded['data'] ?? $decoded;
    }
}
