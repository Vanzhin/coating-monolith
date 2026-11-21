<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Infrastructure\Controller;

use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use App\Waybills\Application\UseCase\Query\GetVehicle\GetVehicleQuery;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use Brick\Math\BigDecimal;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class VehicleControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $prefix;
    private int $region;
    private int $registrationSequence = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->prefix = 'V'.strtoupper(bin2hex(random_bytes(5)));
        $this->region = random_int(10, 999);
        $container = $this->client->getContainer();
        $user = new User(new Email(strtolower($this->prefix).'@example.com'));
        $user->setPassword('password', $container->get(UserPasswordHasherInterface::class));
        (new \ReflectionProperty($user, 'isActive'))->setValue($user, true);
        (new \ReflectionProperty($user, 'roles'))->setValue($user, ['ROLE_ADMIN']);
        $em = $container->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $connection = static::getContainer()->get('doctrine')->getConnection();
        $connection->executeStatement('DELETE FROM waybills_vehicle WHERE model LIKE :pattern', ['pattern' => '%'.$this->prefix.'%']);
        parent::tearDown();
    }

    public function test_regular_user_creates_and_edits_vehicle(): void
    {
        $registration = $this->registration();
        $url = '/cabinet/waybill/vehicle/new';
        $this->client->request('POST', $url, $this->creationInput($this->token($url), 'Грузовик '.$this->prefix, $registration));
        self::assertResponseRedirects('/cabinet/waybill/vehicle');
        $crawler = $this->client->request('GET', '/cabinet/waybill/vehicle?q='.$registration);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ecard', 'Грузовик');
        self::assertCount(0, $crawler->filter('a[href$="/readings"]'));
        $edit = $crawler->filter('a:contains("Изменить")')->attr('href');
        self::assertNotNull($edit);

        $this->client->request('POST', $edit, [
            '_token' => $this->token($edit), 'model' => 'Новая модель '.$this->prefix, 'registrationNumber' => $registration,
            'fuelTypes' => ['АИ-95', 'АИ-98'], 'defaultFuelType' => 'АИ-98', 'consumptionRate' => '7.5',
        ]);
        self::assertResponseRedirects('/cabinet/waybill/vehicle');
        $this->client->request('GET', $edit);
        self::assertSelectorExists('input[name="model"][value="Новая модель '.$this->prefix.'"]');
        self::assertSelectorExists('input[name="fuelTypes[]"][value="АИ-98"][checked]');
        self::assertSelectorExists('select[name="defaultFuelType"] option[value="АИ-98"][selected]');
        self::assertSelectorExists('input[name="consumptionRate"][value="7.5"]');
    }

    public function test_form_errors_and_csrf_preserve_input(): void
    {
        $url = '/cabinet/waybill/vehicle/new';
        $input = $this->creationInput($this->token($url), 'Сохрани меня '.$this->prefix, 'А123');
        $this->client->request('POST', $url, $input);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'латинск');
        self::assertSelectorExists('input[name="model"][value="Сохрани меня '.$this->prefix.'"]');
        self::assertSelectorExists('input[name="fuelTypes[]"][value="АИ-95"][checked]');
        $input['_token'] = 'invalid';
        $this->client->request('POST', $url, $input);
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorExists('input[name="model"][value="Сохрани меня '.$this->prefix.'"]');
    }

    public function test_invalid_registration_and_non_numeric_rate_are_rejected(): void
    {
        $url = '/cabinet/waybill/vehicle/new';
        $input = $this->creationInput($this->token($url), 'Модель '.$this->prefix, str_repeat('A', 33));
        $this->client->request('POST', $url, $input);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'A123BC77');
        self::assertSelectorExists('input[name="registrationNumber"][value="'.str_repeat('A', 33).'"]');
        $input['registrationNumber'] = $this->registration();
        $input['consumptionRate'] = 'abc';
        $this->client->request('POST', $url, $input);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'число');
        self::assertSelectorExists('input[name="consumptionRate"][value="abc"]');
    }

    public function test_search_pagination_and_suggest(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        for ($i = 0; $i < 11; ++$i) {
            $em->persist($this->vehicle('Поиск '.$this->prefix.' '.sprintf('%02d', $i), $this->registration()));
        }
        $em->flush();
        $crawler = $this->client->request('GET', '/cabinet/waybill/vehicle?q='.$this->prefix);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pagination', 'Вперёд');
        self::assertCount(10, $crawler->filter('.ecard'));
        $crawler = $this->client->request('GET', '/cabinet/waybill/vehicle?q='.$this->prefix.'&page=2');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pagination', 'Назад');
        self::assertCount(1, $crawler->filter('.ecard'));
        $this->client->request('GET', '/cabinet/waybill/vehicle/suggest?q='.$this->prefix.'&page=1');
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $result = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(10, $result['items']);
        self::assertSame(1, $result['page']);
        self::assertTrue($result['hasMore']);
        self::assertArrayHasKey('id', $result['items'][0]);
        self::assertArrayHasKey('title', $result['items'][0]);
        $this->client->request('GET', '/cabinet/waybill/vehicle/suggest?q='.$this->prefix.'&page=2');
        $result = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(1, $result['items']);
        self::assertFalse($result['hasMore']);
    }

    public function test_missing_vehicle_is_not_found(): void
    {
        $this->client->request('GET', '/cabinet/waybill/vehicle/'.Uuid::v7().'/edit');
        self::assertResponseStatusCodeSame(404);
    }

    public function test_duplicate_number_and_invalid_initial_data_preserve_values(): void
    {
        $registration = $this->registration();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($this->vehicle('Первая '.$this->prefix, $registration));
        $em->flush();
        $url = '/cabinet/waybill/vehicle/new';
        $this->client->request('POST', $url, $this->creationInput($this->token($url), 'Вторая '.$this->prefix, $registration));
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'уже существует');
        self::assertSelectorExists('input[name="model"][value="Вторая '.$this->prefix.'"]');

        $input = $this->creationInput($this->token($url), 'Некорректная '.$this->prefix, $this->registration(), ['initialAt' => '2026-02-30T10:00', 'initialOdometer' => '7', 'initialFuelRemaining' => '4']);
        $this->client->request('POST', $url, $input);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'дату');
        self::assertSelectorExists('input[name="initialOdometer"][value="7"]');
        $input['_token'] = 'wrong';
        $input['initialOdometer'] = '77';
        $this->client->request('POST', $url, $input);
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorExists('input[name="initialOdometer"][value="77"]');
    }

    public function test_anonymous_access_and_get_vehicle_handler_are_protected(): void
    {
        $this->client->restart();
        $this->client->request('GET', '/cabinet/waybill/vehicle/suggest');
        self::assertResponseRedirects();
        $this->expectException(ForbiddenException::class);
        static::getContainer()->get(QueryBusInterface::class)->execute(new GetVehicleQuery((string) Uuid::v7()));
    }

    private function token(string $url): string
    {
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('input[name="_token"]')->attr('value');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function creationInput(string $token, string $model, string $registration, array $overrides = []): array
    {
        return array_merge([
            '_token' => $token, 'model' => $model, 'registrationNumber' => $registration,
            'fuelTypes' => ['АИ-95'], 'defaultFuelType' => 'АИ-95', 'consumptionRate' => '',
            'initialAt' => '2026-01-01T00:00', 'initialCountry' => 'Россия', 'initialCity' => 'База',
            'initialStreet' => '', 'initialHouse' => '', 'initialOdometer' => '0', 'initialFuelRemaining' => '0',
        ], $overrides);
    }

    private function registration(): string
    {
        return sprintf('A%03dBC%d', ++$this->registrationSequence, $this->region);
    }

    private function vehicle(string $model, string $registration): Vehicle
    {
        return new Vehicle(Uuid::v7(), $model, new RegistrationNumber($registration), new \DateTimeImmutable('2026-01-01'), new Location('Россия', 'База'), 0, BigDecimal::zero());
    }
}
