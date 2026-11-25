<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Infrastructure\Controller;

use App\Shared\Infrastructure\Database\Migrations\Version20261121000000;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000001;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000002;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000003;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000004;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000005;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000006;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000007;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000008;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000009;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000010;
use App\Shared\Infrastructure\Database\Migrations\Version20261122000000;
use App\Shared\Infrastructure\Database\Migrations\Version20261122000001;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class VehicleOccupancyControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private User $first;
    private User $second;
    private string $vehicleId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $connection = $em->getConnection();
        self::assertSame('test_db', $connection->getParams()['host'] ?? null);
        self::assertSame('app_test', $connection->getParams()['dbname'] ?? null);
        $connection->executeStatement('CREATE TEMPORARY TABLE user_user (ulid VARCHAR(26) PRIMARY KEY, email VARCHAR(255) NOT NULL UNIQUE, password VARCHAR(255), roles JSON NOT NULL, is_active BOOLEAN NOT NULL)');
        $connection->executeStatement('CREATE TEMPORARY TABLE user_channel (id UUID PRIMARY KEY, owner_id VARCHAR(26) NOT NULL REFERENCES user_user(ulid), type VARCHAR(100) NOT NULL, value TEXT NOT NULL, is_verified BOOLEAN NOT NULL, verified_at TIMESTAMP(0) WITHOUT TIME ZONE)');
        $connection->executeStatement('CREATE TEMPORARY TABLE notification (id UUID PRIMARY KEY, owner_id VARCHAR(26) NOT NULL, is_read BOOLEAN NOT NULL)');
        foreach ([Version20261121000000::class, Version20261121000001::class, Version20261121000002::class, Version20261121000003::class, Version20261121000004::class, Version20261121000005::class, Version20261121000006::class, Version20261121000007::class, Version20261121000008::class, Version20261121000009::class, Version20261121000010::class, Version20261122000000::class, Version20261122000001::class] as $migrationClass) {
            $migration = new $migrationClass($connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $query->getStatement());
                $connection->executeQuery($sql, $query->getParameters(), $query->getTypes());
            }
        }

        $this->first = $this->user();
        $this->second = $this->user();
        $vehicle = new Vehicle(
            Uuid::v7(), 'Тестовая машина', new RegistrationNumber('a123bc77'),
            new \DateTimeImmutable('2026-01-01'), new Location('База'), 100, BigDecimal::of('30.1'),
        );
        $this->vehicleId = $vehicle->getId();
        foreach ([$this->first, $this->second] as $user) {
            $em->persist($user);
            $em->persist(new DriverProfile(
                Uuid::v7(), $user->getUlid(), new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'),
                new Location('База'),
            ));
        }
        $em->persist($vehicle);
        $em->flush();
        $this->client->loginUser($this->first);
    }

    public function test_driver_can_enter_and_leave_without_javascript(): void
    {
        $crawler = $this->client->request('GET', '/cabinet/waybill/vehicle?q=a123');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ecard', 'Тестовая машина');
        self::assertSelectorExists('.ecard .ecard-media .bi-car-front');
        self::assertSelectorExists('.ecard [aria-label="Госномер A123BC77"]');
        self::assertSelectorTextContains('.ecard [aria-label="Госномер A123BC77"] .border-start', '77 RUS');
        self::assertSelectorNotExists('.ecard .ecard-meta');
        self::assertSelectorExists('.ecard .bi-speedometer2');
        self::assertSelectorExists('.ecard .bi-fuel-pump');
        self::assertSelectorTextContains('.ecard .visually-hidden', 'Пробег');
        self::assertSelectorTextContains('.ecard .bi-fuel-pump + .visually-hidden', 'Остаток топлива');
        self::assertSelectorTextContains('.ecard', '100 км');
        self::assertSelectorTextContains('.ecard', '30.1 л');
        self::assertSelectorExists('.ecard a.stretched-link[href="/cabinet/waybill/vehicle/'.$this->vehicleId.'"][data-action="click->entity-preview#open:prevent"]');
        self::assertSelectorExists('[data-controller="entity-preview"][data-entity-preview-endpoint-value="/cabinet/waybill/vehicle/00000000-0000-0000-0000-000000000000/preview"]');
        self::assertSelectorExists('.ecard-kebab button[data-bs-toggle="dropdown"] .bi-three-dots-vertical');
        self::assertSelectorExists('.ecard .dropdown-menu a[href="/cabinet/waybill/vehicle/'.$this->vehicleId.'/edit"]');
        self::assertSelectorExists('.ecard noscript a[href="/cabinet/waybill/vehicle/'.$this->vehicleId.'/edit"]');
        self::assertSelectorExists('form[method="get"] input[name="q"][value="a123"]');
        $enter = '/cabinet/waybill/vehicle/'.$this->vehicleId.'/enter';
        self::assertSelectorExists('.ecard .dropdown-menu form[action="'.$enter.'"]');
        $form = $crawler->filter('noscript form[action="'.$enter.'"]');
        self::assertCount(1, $form);
        self::assertSame('a123', $form->filter('input[name="q"]')->attr('value'));
        self::assertSame('1', $form->filter('input[name="page"]')->attr('value'));
        $token = $form->filter('input[name="_token"]')->attr('value');
        $this->client->request('POST', $enter, ['_token' => $token, 'q' => 'a123', 'page' => 1]);
        self::assertResponseRedirects('/cabinet/waybill/vehicle?q=a123&page=1');
        $crawler = $this->client->followRedirect();
        $leave = '/cabinet/waybill/vehicle/'.$this->vehicleId.'/leave';
        self::assertSelectorExists('.ecard .dropdown-menu form[action="'.$leave.'"]');
        $form = $crawler->filter('noscript form[action="'.$leave.'"]');
        self::assertCount(1, $form);
        $this->client->request('POST', $leave, ['_token' => $form->filter('input[name="_token"]')->attr('value')]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorExists('form[action="'.$enter.'"]');
    }

    public function test_forged_requests_cannot_take_or_release_an_occupied_vehicle(): void
    {
        $enter = '/cabinet/waybill/vehicle/'.$this->vehicleId.'/enter';
        $crawler = $this->client->request('GET', '/cabinet/waybill/vehicle');
        $token = $crawler->filter('form[action="'.$enter.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $enter, ['_token' => $token]);
        self::assertResponseRedirects();

        $this->client->loginUser($this->second);
        $this->client->request('GET', '/cabinet/waybill/vehicle');
        self::assertSelectorNotExists('.ecard .ecard-meta');
        self::assertSelectorNotExists('form[action="'.$enter.'"]');
        $this->client->request('GET', '/cabinet/waybill/vehicle/'.$this->vehicleId.'/preview');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.modal-body', 'Занят');
        $this->client->request('POST', $enter, ['_token' => $token]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'В автомобиле уже есть водитель.');

        $leave = '/cabinet/waybill/vehicle/'.$this->vehicleId.'/leave';
        $this->client->request('POST', $leave, ['_token' => $token]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Недостаточно прав');
        $vehicle = static::getContainer()->get(EntityManagerInterface::class)->find(Vehicle::class, $this->vehicleId);
        self::assertTrue($vehicle?->isDrivenBy($this->first->getUlid()));
    }

    public function test_invalid_csrf_cannot_enter(): void
    {
        $this->client->request('POST', '/cabinet/waybill/vehicle/'.$this->vehicleId.'/enter', ['_token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        $vehicle = static::getContainer()->get(EntityManagerInterface::class)->find(Vehicle::class, $this->vehicleId);
        self::assertNull($vehicle?->driver());
    }

    public function test_preview_and_page_show_vehicle_details_without_losing_fuel_precision(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = new Vehicle(
            Uuid::v7(), 'Другая машина', new RegistrationNumber('x121ta777'),
            new \DateTimeImmutable('2026-01-01'), new Location('База'), 100,
            BigDecimal::of('30.00000000000000000001'), null, FuelType::Ai95, FuelType::Ai92, FuelType::Ai95,
        );
        $em->persist($vehicle);
        $em->flush();
        $url = '/cabinet/waybill/vehicle/'.$vehicle->getId();

        $this->client->request('GET', $url.'/preview');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.modal-title', 'Другая машина');
        self::assertSelectorTextContains('.modal-body', 'X121TA777');
        self::assertSelectorTextContains('.modal-body', 'АИ-92, АИ-95');
        self::assertSelectorTextContains('.modal-body', 'Свободен');
        self::assertSelectorTextContains('.modal-body', '100');
        self::assertSelectorTextContains('.modal-body', '30.00000000000000000001');

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Другая машина');
        self::assertSelectorTextContains('.kv-grid', '30.00000000000000000001');
        self::assertSelectorNotExists('.modal');
    }

    public function test_preview_and_page_return_not_found_for_missing_vehicle(): void
    {
        $url = '/cabinet/waybill/vehicle/'.Uuid::v7();
        $this->client->request('GET', $url.'/preview');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(404);
    }

    public function test_anonymous_user_cannot_open_vehicle_preview(): void
    {
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/cabinet/waybill/vehicle/'.$this->vehicleId.'/preview');
        self::assertResponseRedirects();
    }

    public function test_create_vehicle_from_form_with_initial_state(): void
    {
        $crawler = $this->client->request('GET', '/cabinet/waybill/vehicle/new');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="type"]');
        self::assertSelectorExists('input[name="fuelTypes[]"][value="АИ-95"][checked]');
        self::assertSelectorExists('select[name="defaultFuelType"] option[value="АИ-95"][selected]');
        self::assertSelectorExists('input[name="initialAt"][type="datetime-local"]');
        $token = $crawler->filter('form#vehicle-form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/cabinet/waybill/vehicle/new', ['_token' => $token] + $this->newVehicleData());

        self::assertResponseRedirects('/cabinet/waybill/vehicle');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.ecard-grid', 'Новый автомобиль');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->getRepository(Vehicle::class)->findOneBy(['model' => 'Новый автомобиль']);
        self::assertInstanceOf(Vehicle::class, $vehicle);
        self::assertSame('b123cc77', $vehicle->getRegistrationNumber()->value());
        self::assertSame([FuelType::Ai92, FuelType::Ai95], $vehicle->getFuelTypes());
        self::assertSame(FuelType::Ai95, $vehicle->getDefaultFuelType());
        self::assertSame('2026-01-02 10:30', $vehicle->initialAt()->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i'));
        self::assertSame('Россия, Москва, База, 1', $vehicle->initialLocation()->toString());
        self::assertSame(123, $vehicle->initialOdometer());
        self::assertSame('30.00000000000000000001', (string) $vehicle->initialFuelRemaining());
        self::assertNull($vehicle->driver());
    }

    public function test_create_form_preserves_input_when_initial_fuel_is_invalid(): void
    {
        $crawler = $this->client->request('GET', '/cabinet/waybill/vehicle/new');
        $token = $crawler->filter('form#vehicle-form input[name="_token"]')->attr('value');
        $data = $this->newVehicleData();
        $data['initialFuelRemaining'] = '-1';

        $this->client->request('POST', '/cabinet/waybill/vehicle/new', ['_token' => $token] + $data);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'Остаток топлива должен быть неотрицательным');
        self::assertSelectorExists('input[name="model"][value="Новый автомобиль"]');
        self::assertSelectorExists('input[name="initialFuelRemaining"][value="-1"]');
        self::assertSelectorExists('input[name="fuelTypes[]"][value="АИ-92"][checked]');
        self::assertSelectorExists('input[name="fuelTypes[]"][value="АИ-95"][checked]');
    }

    public function test_create_form_rejects_invalid_registration_on_the_server(): void
    {
        $crawler = $this->client->request('GET', '/cabinet/waybill/vehicle/new');
        $token = $crawler->filter('form#vehicle-form input[name="_token"]')->attr('value');
        $data = $this->newVehicleData();
        $data['registrationNumber'] = 'А123ВС77';

        $this->client->request('POST', '/cabinet/waybill/vehicle/new', ['_token' => $token] + $data);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'латинскими буквами');
        self::assertSelectorExists('input[name="registrationNumber"][value="А123ВС77"]');

        $data['registrationNumber'] = 'A000BC77';
        $this->client->request('POST', '/cabinet/waybill/vehicle/new', ['_token' => $token] + $data);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', '000 недопустимо');
        self::assertSelectorExists('input[name="registrationNumber"][value="A000BC77"]');
    }

    public function test_create_form_rejects_default_fuel_outside_selection(): void
    {
        $crawler = $this->client->request('GET', '/cabinet/waybill/vehicle/new');
        $token = $crawler->filter('form#vehicle-form input[name="_token"]')->attr('value');
        $data = $this->newVehicleData();
        $data['fuelTypes'] = ['АИ-92'];

        $this->client->request('POST', '/cabinet/waybill/vehicle/new', ['_token' => $token] + $data);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'Топливо по умолчанию должно входить');
        self::assertSelectorExists('input[name="fuelTypes[]"][value="АИ-92"][checked]');
        self::assertSelectorNotExists('input[name="fuelTypes[]"][value="АИ-95"][checked]');
    }

    public function test_edit_uses_the_same_fuel_fields_and_preserves_vehicle_state(): void
    {
        $enter = '/cabinet/waybill/vehicle/'.$this->vehicleId.'/enter';
        $crawler = $this->client->request('GET', '/cabinet/waybill/vehicle');
        $token = $crawler->filter('.dropdown-menu form[action="'.$enter.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $enter, ['_token' => $token]);
        self::assertResponseRedirects();

        $url = '/cabinet/waybill/vehicle/'.$this->vehicleId.'/edit';
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="registrationNumber"][value="A123BC77"]');
        self::assertSelectorExists('input[name="fuelTypes[]"][value="АИ-95"][checked]');
        self::assertSelectorExists('select[name="defaultFuelType"] option[value="АИ-95"][selected]');
        self::assertSelectorNotExists('textarea[name="fuelTypes"]');
        self::assertSelectorNotExists('input[name="initialAt"]');
        $token = $crawler->filter('form#vehicle-form input[name="_token"]')->attr('value');

        $this->client->request('POST', $url, ['_token' => $token] + $this->editVehicleData() + [
            'initialAt' => '2030-01-01T00:00', 'initialOdometer' => '999', 'initialFuelRemaining' => '999',
        ]);

        self::assertResponseRedirects('/cabinet/waybill/vehicle');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        self::assertInstanceOf(Vehicle::class, $vehicle);
        self::assertSame('Обновлённая машина', $vehicle->getModel());
        self::assertSame('b123cc77', $vehicle->getRegistrationNumber()->value());
        self::assertSame([FuelType::Ai92, FuelType::Ai95], $vehicle->getFuelTypes());
        self::assertSame(FuelType::Ai92, $vehicle->getDefaultFuelType());
        self::assertSame(8.5, $vehicle->getConsumptionRate()?->value());
        self::assertSame('2026-01-01', $vehicle->initialAt()->format('Y-m-d'));
        self::assertSame('База', $vehicle->initialLocation()->toString());
        self::assertSame(100, $vehicle->initialOdometer());
        self::assertSame('30.1', (string) $vehicle->initialFuelRemaining());
        self::assertSame(100, $vehicle->currentOdometer());
        self::assertSame('30.1', (string) $vehicle->fuelRemaining());
        self::assertTrue($vehicle->isDrivenBy($this->first->getUlid()));

        $this->client->request('GET', $url);
        self::assertSelectorExists('input[name="fuelTypes[]"][value="АИ-92"][checked]');
        self::assertSelectorExists('input[name="fuelTypes[]"][value="АИ-95"][checked]');
        self::assertSelectorExists('select[name="defaultFuelType"] option[value="АИ-92"][selected]');
    }

    public function test_edit_invalid_fuel_selection_preserves_input_and_does_not_save(): void
    {
        $url = '/cabinet/waybill/vehicle/'.$this->vehicleId.'/edit';
        $crawler = $this->client->request('GET', $url);
        $token = $crawler->filter('form#vehicle-form input[name="_token"]')->attr('value');
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        $data = $this->editVehicleData();
        $data['defaultFuelType'] = 'АИ-98';

        $this->client->request('POST', $url, ['_token' => $token] + $data);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'Топливо по умолчанию должно входить');
        self::assertSelectorExists('input[name="model"][value="Обновлённая машина"]');
        self::assertSelectorExists('input[name="fuelTypes[]"][value="АИ-92"][checked]');
        self::assertSelectorExists('select[name="defaultFuelType"] option[value="АИ-98"][selected]');
        $row = $connection->fetchAssociative('SELECT model, registration_number, default_fuel_type FROM waybills_vehicle WHERE id = ?', [$this->vehicleId]);
        self::assertSame('Тестовая машина', $row['model']);
        self::assertSame('a123bc77', $row['registration_number']);
        self::assertSame('АИ-95', $row['default_fuel_type']);
    }

    public function test_edit_rejects_duplicate_number_and_preserves_input(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $second = new Vehicle(
            Uuid::v7(), 'Вторая машина', new RegistrationNumber('b123cc77'),
            new \DateTimeImmutable('2026-01-01'), new Location('База'), 0, BigDecimal::zero(),
        );
        $em->persist($second);
        $em->flush();
        $connection = $em->getConnection();
        $url = '/cabinet/waybill/vehicle/'.$this->vehicleId.'/edit';
        $crawler = $this->client->request('GET', $url);
        $token = $crawler->filter('form#vehicle-form input[name="_token"]')->attr('value');

        $this->client->request('POST', $url, ['_token' => $token] + $this->editVehicleData());

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'Автомобиль с таким госномером уже существует.');
        self::assertSelectorExists('input[name="registrationNumber"][value="B123CC77"]');
        self::assertSame('Тестовая машина', $connection->fetchOne('SELECT model FROM waybills_vehicle WHERE id = ?', [$this->vehicleId]));
    }

    public function test_edit_rejects_invalid_csrf(): void
    {
        $this->client->request('POST', '/cabinet/waybill/vehicle/'.$this->vehicleId.'/edit', ['_token' => 'invalid'] + $this->editVehicleData());

        self::assertResponseStatusCodeSame(403);
        $vehicle = static::getContainer()->get(EntityManagerInterface::class)->find(Vehicle::class, $this->vehicleId);
        self::assertSame('Тестовая машина', $vehicle?->getModel());
    }

    public function test_edit_accepts_own_registration_number_in_another_case(): void
    {
        $url = '/cabinet/waybill/vehicle/'.$this->vehicleId.'/edit';
        $crawler = $this->client->request('GET', $url);
        $token = $crawler->filter('form#vehicle-form input[name="_token"]')->attr('value');
        $data = $this->editVehicleData();
        $data['registrationNumber'] = 'a123BC77';
        $data['consumptionRate'] = '';

        $this->client->request('POST', $url, ['_token' => $token] + $data);

        self::assertResponseRedirects('/cabinet/waybill/vehicle');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        self::assertSame('a123bc77', $vehicle?->getRegistrationNumber()->value());
        self::assertNull($vehicle?->getConsumptionRate());
    }

    public function test_edit_rejects_unknown_fuel_type(): void
    {
        $url = '/cabinet/waybill/vehicle/'.$this->vehicleId.'/edit';
        $crawler = $this->client->request('GET', $url);
        $token = $crawler->filter('form#vehicle-form input[name="_token"]')->attr('value');
        $data = $this->editVehicleData();
        $data['fuelTypes'] = ['АИ-92', 'ДТ'];

        $this->client->request('POST', $url, ['_token' => $token] + $data);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'Укажите допустимую марку топлива.');
        self::assertSelectorExists('input[name="fuelTypes[]"][value="АИ-92"][checked]');
    }

    public function test_driver_in_another_vehicle_sees_the_server_error(): void
    {
        $secondVehicle = new Vehicle(
            Uuid::v7(), 'Вторая машина', new RegistrationNumber('a124bc77'),
            new \DateTimeImmutable('2026-01-01'), new Location('База'), 100, BigDecimal::of('30'),
        );
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($secondVehicle);
        $em->flush();
        $firstUrl = '/cabinet/waybill/vehicle/'.$this->vehicleId.'/enter';
        $secondUrl = '/cabinet/waybill/vehicle/'.$secondVehicle->getId().'/enter';
        $crawler = $this->client->request('GET', '/cabinet/waybill/vehicle');
        $token = $crawler->filter('form[action="'.$firstUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $firstUrl, ['_token' => $token]);
        $crawler = $this->client->followRedirect();
        $token = $crawler->filter('form[action="'.$secondUrl.'"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $secondUrl, ['_token' => $token]);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('[role="alert"]', 'Водитель уже находится в другом автомобиле.');
    }

    public function test_suggest_endpoint_keeps_its_existing_response(): void
    {
        $this->client->request('GET', '/cabinet/waybill/vehicle/suggest?q=a123');
        self::assertResponseIsSuccessful();
        $result = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($this->vehicleId, $result['items'][0]['id']);
        self::assertSame('Тестовая машина — A123BC77', $result['items'][0]['title']);
        self::assertFalse($result['hasMore']);
    }

    #[DataProvider('registrationNumberCases')]
    public function test_search_finds_registration_number_regardless_of_case(string $number): void
    {
        $this->client->request('GET', '/cabinet/waybill/vehicle', ['q' => $number]);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.ecard [aria-label="Госномер A123BC77"]');

        $this->client->request('GET', '/cabinet/waybill/vehicle/suggest', ['q' => $number]);
        self::assertResponseIsSuccessful();
        $result = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($this->vehicleId, $result['items'][0]['id']);
        self::assertSame('Тестовая машина — A123BC77', $result['items'][0]['title']);
        self::assertCount(1, $result['items']);
    }

    #[DataProvider('registrationNumberCases')]
    public function test_create_rejects_duplicate_registration_number_regardless_of_case(string $number): void
    {
        $crawler = $this->client->request('GET', '/cabinet/waybill/vehicle/new');
        $token = $crawler->filter('form#vehicle-form input[name="_token"]')->attr('value');
        $data = $this->newVehicleData();
        $data['registrationNumber'] = $number;

        $this->client->request('POST', '/cabinet/waybill/vehicle/new', ['_token' => $token] + $data);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'Автомобиль с таким госномером уже существует.');
    }

    /** @return iterable<string, array{string}> */
    public static function registrationNumberCases(): iterable
    {
        yield 'lowercase' => ['a123bc77'];
        yield 'mixed case' => ['a123BC77'];
        yield 'uppercase' => ['A123BC77'];
    }

    public function test_card_shows_three_digit_registration_region(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new Vehicle(
            Uuid::v7(), 'Другая машина', new RegistrationNumber('x121ta777'),
            new \DateTimeImmutable('2026-01-01'), new Location('База'), 0, BigDecimal::zero(),
        ));
        $em->flush();

        $this->client->request('GET', '/cabinet/waybill/vehicle?q=x121ta777');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.ecard [aria-label="Госномер X121TA777"]');
        self::assertSelectorTextContains('.ecard [aria-label="Госномер X121TA777"] .border-start', '777 RUS');
    }

    public function test_vehicle_list_and_suggest_keep_pagination(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        for ($i = 0; $i < 10; ++$i) {
            $em->persist(new Vehicle(
                Uuid::v7(), 'Машина '.sprintf('%02d', $i), new RegistrationNumber(sprintf('b%03daa77', $i + 1)),
                new \DateTimeImmutable('2026-01-01'), new Location('База'), 0, BigDecimal::zero(),
            ));
        }
        $em->flush();

        $this->client->request('GET', '/cabinet/waybill/vehicle?page=2');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.pagination', 'Назад');
        self::assertSelectorCount(1, '.ecard-grid .ecard');

        $this->client->request('GET', '/cabinet/waybill/vehicle/suggest?page=1');
        $result = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertCount(10, $result['items']);
        self::assertTrue($result['hasMore']);
    }

    public function test_empty_page_keeps_search_and_previous_page_link(): void
    {
        $this->client->request('GET', '/cabinet/waybill/vehicle?q=missing&page=2');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.text-muted', 'Автомобили не найдены.');
        self::assertSelectorExists('form[method="get"] input[name="q"][value="missing"]');
        self::assertSelectorExists('.pagination a[href="/cabinet/waybill/vehicle?page=1&q=missing"]');
    }

    private function user(): User
    {
        $user = new User(new Email(bin2hex(random_bytes(8)).'@example.com'));
        (new \ReflectionProperty($user, 'isActive'))->setValue($user, true);
        (new \ReflectionProperty($user, 'roles'))->setValue($user, ['ROLE_ADMIN']);

        return $user;
    }

    /** @return array<string, string|list<string>> */
    private function newVehicleData(): array
    {
        return [
            'model' => 'Новый автомобиль', 'registrationNumber' => 'B123CC77',
            'consumptionRate' => '8.5', 'fuelTypes' => ['АИ-92', 'АИ-95'], 'defaultFuelType' => 'АИ-95',
            'initialAt' => '2026-01-02T10:30', 'initialCountry' => 'Россия', 'initialCity' => 'Москва',
            'initialStreet' => 'База', 'initialHouse' => '1', 'initialOdometer' => '123',
            'initialFuelRemaining' => '30.00000000000000000001',
        ];
    }

    /** @return array<string, string|list<string>> */
    private function editVehicleData(): array
    {
        return [
            'model' => 'Обновлённая машина', 'registrationNumber' => 'B123CC77',
            'consumptionRate' => '8.5', 'fuelTypes' => ['АИ-92', 'АИ-95'], 'defaultFuelType' => 'АИ-92',
        ];
    }
}
