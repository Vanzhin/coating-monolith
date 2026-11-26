<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Infrastructure\Controller;

use App\Shared\Domain\Audit\TrackedClassRepositoryInterface;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Audit\CachedAuditPolicy;
use App\Shared\Infrastructure\Database\DBAL\ExactDateTimeType;
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
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Waybills\Application\DTO\TripInput;
use App\Waybills\Application\Service\LocationCatalog;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Location\Location as CatalogLocation;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Route\Settlement;
use App\Waybills\Domain\Aggregate\Route\SettlementType;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Infrastructure\Repository\WaybillRepository;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

final class WaybillControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private Connection $connection;
    private string $vehicleId;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        static::getContainer()->set(CachedAuditPolicy::class, new CachedAuditPolicy(static::getContainer()->get(TrackedClassRepositoryInterface::class), new ArrayAdapter()));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = $em->getConnection();
        self::assertSame('test_db', $this->connection->getParams()['host'] ?? null);
        self::assertSame('app_test', $this->connection->getParams()['dbname'] ?? null);
        $this->connection->executeStatement('CREATE TEMPORARY TABLE user_user (ulid VARCHAR(26) PRIMARY KEY, email VARCHAR(255) NOT NULL, password VARCHAR(255), roles JSON NOT NULL, is_active BOOLEAN NOT NULL)');
        $this->connection->executeStatement('CREATE TEMPORARY TABLE notification (id UUID PRIMARY KEY, owner_id TEXT NOT NULL, is_read BOOLEAN NOT NULL)');
        $this->connection->executeStatement('CREATE TEMPORARY TABLE audit_tracked_class (id UUID PRIMARY KEY, entity_class TEXT NOT NULL, fields JSON NOT NULL)');
        foreach ([Version20261121000000::class, Version20261121000001::class, Version20261121000002::class, Version20261121000003::class, Version20261121000004::class, Version20261121000005::class, Version20261121000006::class, Version20261121000007::class, Version20261121000008::class, Version20261121000009::class, Version20261121000010::class, Version20261122000000::class, Version20261122000001::class] as $migrationClass) {
            $this->applyTemporaryMigration($migrationClass);
        }
        foreach (['user_user', 'notification', 'audit_tracked_class', 'waybills_vehicle', 'waybills_driver_profile', 'waybills_waybill', 'waybills_route_log'] as $table) {
            self::assertNotNull($this->connection->fetchOne('SELECT to_regclass(?)', ['pg_temp.'.$table]));
        }
        $user = new User(new Email('waybill-web@example.com'));
        (new \ReflectionProperty($user, 'roles'))->setValue($user, ['ROLE_ADMIN']);
        (new \ReflectionProperty($user, 'isActive'))->setValue($user, true);
        $this->connection->executeStatement("INSERT INTO pg_temp.user_user (ulid, email, password, roles, is_active) VALUES (?, ?, 'test', '[\"ROLE_ADMIN\"]', true)", [$user->getUlid(), 'waybill-web@example.com']);
        $vehicle = new Vehicle(Uuid::v7(), 'Модель', new RegistrationNumber('A123BC77'), new \DateTimeImmutable('2025-01-01'), new Location('Россия', 'База'), 100, BigDecimal::of('30.00000000000000000001'), null, FuelType::Ai95, FuelType::Ai95, FuelType::Ai92);
        $driver = new DriverProfile(Uuid::v7(), $user->getUlid(), new DriverDetails('Водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), new Location('Россия', 'База'));
        $vehicle->enter($driver);
        $em->persist($driver);
        $em->persist($vehicle);
        $em->flush();
        $this->vehicleId = $vehicle->getId();
        $this->client->loginUser($user);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        parent::tearDown();
    }

    public function test_waybill_list_is_linked_and_active_in_desktop_and_mobile_menus(): void
    {
        $crawler = $this->client->request('GET', '/cabinet/waybill/sheets');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.app-sidebar a.active[href="/cabinet/waybill/sheets"][aria-current="page"]'));
        self::assertCount(1, $crawler->filter('#mainMenu a.active[href="/cabinet/waybill/sheets"][data-action="click->offcanvas-autoclose#close"]'));
    }

    public function test_shared_list_page_still_renders_its_filter_block(): void
    {
        $twig = static::getContainer()->get('twig');
        $template = $twig->createTemplate(<<<'TWIG'
            {% embed 'components/list_page.html.twig' with {title: 'Список', create_route: null, can_edit: false, is_empty: false} %}
                {% block above_list %}<span>Фильтры списка</span>{% endblock %}
            {% endembed %}
            TWIG);

        self::assertStringContainsString('<span>Фильтры списка</span>', $template->render());
    }

    public function test_add_button_opens_creation_for_the_current_drivers_vehicle(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $otherDriver = new DriverProfile(Uuid::v7(), 'other-driver', new DriverDetails('Другой водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), new Location('База'));
        $otherVehicle = new Vehicle(Uuid::v7(), 'А автомобиль другого водителя', new RegistrationNumber('B124CC77'), new \DateTimeImmutable('2025-01-01'), new Location('База'), 0, BigDecimal::zero());
        $otherVehicle->enter($otherDriver);
        $em->persist($otherDriver);
        $em->persist($otherVehicle);
        $otherDraft = Waybill::createDraft(Uuid::v7(), 2, $otherVehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31')));
        $em->persist($otherDraft);
        $em->flush();

        $crawler = $this->client->request('GET', '/cabinet/waybill/sheets');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a.btn-soft-success[title="Добавить"][href="/cabinet/waybill/sheets/new"]'));
        self::assertCount(0, $crawler->filter('a.btn-outline-secondary[href="/cabinet/waybill/vehicle"]'));
        self::assertCount(0, $crawler->filterXPath('//h2[normalize-space(.)="Путевые листы"]/parent::div/following-sibling::div[contains(concat(" ", normalize-space(@class), " "), " mb-3 ")]'));
        self::assertSelectorTextContains('body', 'Путевые листы не найдены');
        self::assertSelectorTextNotContains('body', 'Путевой лист №2');
        $this->client->request('GET', '/cabinet/waybill/sheets/new');
        self::assertResponseRedirects('/cabinet/waybill/sheets/new/'.$this->vehicleId);
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Модель');
        $this->client->request('POST', '/cabinet/waybill/sheets/new/'.$this->vehicleId, $this->creationInput($crawler));
        self::assertResponseRedirects();
        $this->client->request('GET', '/cabinet/waybill/sheets');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.ecard', 'Путевой лист №1');
        self::assertSelectorTextContains('.ecard', 'Модель');
        self::assertSelectorTextNotContains('body', 'Путевой лист №2');
    }

    public function test_add_without_an_occupied_vehicle_explains_how_to_choose_one(): void
    {
        $this->connection->executeStatement('UPDATE pg_temp.waybills_vehicle SET driver_id = NULL WHERE id = ?', [$this->vehicleId]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->request('GET', '/cabinet/waybill/sheets/new');
        self::assertResponseRedirects('/cabinet/waybill/vehicle');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash-messages', 'сначала сядьте в автомобиль');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));
    }

    public function test_fill_button_preserves_edited_automatic_pair_without_saving(): void
    {
        $payload = ['country' => 'Россия', 'city' => 'Клиент', 'cityType' => 'city', 'street' => '', 'house' => '', 'enterprise' => '', 'latitude' => '56.8', 'longitude' => '60.6'];
        $this->client->request('POST', '/cabinet/waybill/locations/quick', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $locationId = Uuid::fromString(($response['data'] ?? $response)['id']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        $vehicle->driver()->replaceDestinations(new Destination(new Location('Россия', new Settlement('Клиент', SettlementType::City), latitude: 56.8, longitude: 60.6, id: $locationId), 15));
        $em->flush();
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;

        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['action'] = 'fill_route';
        $input['odometerEnd'] = '160';
        $crawler = $this->client->request('POST', $url, $input);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
        self::assertCount(4, $crawler->filter('#trip-rows [data-waybill-form-target~="tripRow"]'));
        self::assertCount(0, $crawler->filter('.form-alert'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));

        $input = $this->creationInput($crawler);
        $input['action'] = 'fill_route';
        $input['trips'] = $this->postedTrips($crawler);
        $input['trips'][1]['date'] = $input['trips'][2]['date'];
        self::assertSame((string) $locationId, $input['trips'][2]['locationId']);
        $crawler = $this->client->request('POST', $url, $input);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
        self::assertCount(0, $crawler->filter('.form-alert'));
        self::assertCount(4, $crawler->filter('#trip-rows [data-waybill-form-target~="tripRow"]'));
        $output = $this->postedTrips($crawler);
        $outputById = [];
        foreach ($output as $trip) {
            $outputById[$trip['id']] = $trip;
        }
        $editedIds = [];
        foreach ([0, 1] as $index) {
            $id = $input['trips'][$index]['id'];
            $editedIds[$id] = true;
            self::assertSame('manual', $outputById[$id]['origin']);
        }
        self::assertSame($input['trips'][1]['date'], $outputById[$input['trips'][1]['id']]['date']);
        foreach ($output as $trip) {
            if (!isset($editedIds[$trip['id']])) {
                self::assertSame('automatic', $trip['origin']);
            }
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));

        $input = $this->creationInput($crawler);
        $input['trips'] = $this->postedTrips($crawler);
        $this->client->request('POST', $url, $input);

        self::assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));
        $origins = $this->connection->fetchAllKeyValue('SELECT id, origin FROM pg_temp.waybills_route_log');
        self::assertCount(4, $origins);
        self::assertEqualsCanonicalizing(array_column($input['trips'], 'id'), array_keys($origins));
        foreach ($origins as $id => $origin) {
            self::assertSame(isset($editedIds[$id]) ? 'manual' : 'automatic', $origin);
        }
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_route_log WHERE location->>'id' = ? AND location->>'latitude' = '56.8' AND location->>'longitude' = '60.6'", [(string) $locationId]));
        $showUrl = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($showUrl);
        $crawler = $this->client->request('GET', $showUrl.'/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->client->getCrawler()->filter('#trip-rows details summary i[aria-label="Ручная поездка"]'));
        self::assertCount(2, $this->client->getCrawler()->filter('#trip-rows details summary i[aria-label="Автоматическая поездка"]'));
        $input['_token'] = $crawler->filter('input[name="_token"]')->attr('value');
        $input['version'] = $crawler->filter('input[name="version"]')->attr('value');
        $input['trips'] = $this->postedTrips($crawler);
        $this->connection->executeStatement('UPDATE pg_temp.waybills_location SET latitude = 55.7, longitude = 37.6 WHERE id = ?', [(string) $locationId]);
        $em->clear();
        $this->client->request('POST', $showUrl.'/edit', $input);
        self::assertResponseRedirects($showUrl);
        $updatedOrigins = $this->connection->fetchAllKeyValue('SELECT id, origin FROM pg_temp.waybills_route_log');
        ksort($origins);
        ksort($updatedOrigins);
        self::assertSame($origins, $updatedOrigins);
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_route_log WHERE location->>'id' = ? AND location->>'latitude' = '56.8' AND location->>'longitude' = '60.6'", [(string) $locationId]));
        $this->connection->executeStatement("UPDATE pg_temp.waybills_route_log SET location = jsonb_set(jsonb_set(location, '{latitude}', 'null'::jsonb), '{longitude}', 'null'::jsonb) WHERE location->>'id' = ?", [(string) $locationId]);
        $em->clear();
        $crawler = $this->client->request('GET', $showUrl.'/edit');
        $input['_token'] = $crawler->filter('input[name="_token"]')->attr('value');
        $input['version'] = $crawler->filter('input[name="version"]')->attr('value');
        $input['trips'] = $this->postedTrips($crawler);
        $this->client->request('POST', $showUrl.'/edit', $input);
        self::assertResponseRedirects($showUrl);
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_route_log WHERE location->>'id' = ? AND location->>'latitude' IS NULL", [(string) $locationId]));
        $crawler = $this->client->request('GET', $showUrl.'/edit');
        $input['_token'] = $crawler->filter('input[name="_token"]')->attr('value');
        $input['version'] = $crawler->filter('input[name="version"]')->attr('value');
        $input['trips'] = $this->postedTrips($crawler);
        $input['action'] = 'fill_route';
        $crawler = $this->client->request('POST', $showUrl.'/edit', $input);
        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('#trip-rows input[name$="[origin]"][value="automatic"]'));
        unset($input['action']);
        $input['trips'] = $this->postedTrips($crawler);
        $automaticIds = array_column(array_filter($input['trips'], static fn (array $trip): bool => 'automatic' === $trip['origin']), 'id');
        self::assertCount(2, array_diff($automaticIds, array_keys($updatedOrigins)));
        $this->client->request('POST', $showUrl.'/edit', $input);
        self::assertResponseRedirects($showUrl);
        self::assertEqualsCanonicalizing(array_column($input['trips'], 'id'), $this->connection->fetchFirstColumn('SELECT id FROM pg_temp.waybills_route_log'));
        self::assertGreaterThanOrEqual(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_route_log WHERE location->>'id' = ? AND location->>'latitude' IS NULL", [(string) $locationId]));
    }

    #[DataProvider('foreignTripRequests')]
    public function test_foreign_trip_id_is_rejected_without_changing_saved_data(bool $edit, bool $fill, string $origin): void
    {
        $createUrl = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $createUrl);
        self::assertResponseIsSuccessful();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        $vehicle->driver()->replaceDestinations(new Destination(new Location('Россия', 'Плановый клиент'), 15));
        $otherDriver = new DriverProfile(Uuid::v7(), 'other-driver', new DriverDetails('Другой водитель', '1234567890', new \DateTimeImmutable('2020-01-01'), '11223344595'), new Location('Россия', 'Чужая база'));
        $otherVehicle = new Vehicle(Uuid::v7(), 'Секретная модель', new RegistrationNumber('B124CC77'), new \DateTimeImmutable('2025-01-01'), new Location('Россия', 'Чужая база'), 0, BigDecimal::zero());
        $otherVehicle->enter($otherDriver);
        $em->persist($otherDriver);
        $em->persist($otherVehicle);
        $em->flush();

        $foreignDraft = Waybill::createDraft(Uuid::v7(), 2, $otherVehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31 23:59:59')));
        $foreignTrip = new RouteLog(Uuid::v7(), $otherVehicle, null, new \DateTimeImmutable('2026-01-09'), new Destination(new Location('Россия', 'Секретный склад'), 37), RouteLogOrigin::Manual);
        $foreignDraft->setTrips($foreignTrip);
        (new WaybillRepository($em, $this->connection))->add($foreignDraft);
        $foreignId = (string) $foreignTrip->id;
        self::assertSame($foreignDraft->getId(), $this->connection->fetchOne('SELECT draft_waybill_id FROM pg_temp.waybills_route_log WHERE id = ?', [$foreignId]));
        $em->clear();

        $input = $this->creationInput($crawler);
        $url = $createUrl;
        if ($edit) {
            $input['odometerEnd'] = '130';
            $input['trips'] = [
                ['id' => '', 'date' => '2026-01-06', 'distance' => '15', 'country' => 'Россия', 'city' => 'Свой клиент'],
                ['id' => '', 'date' => '2026-01-07', 'distance' => '15', 'country' => 'Россия', 'city' => 'База'],
            ];
            $this->client->request('POST', $createUrl, $input);
            self::assertResponseRedirects();
            $showUrl = $this->client->getResponse()->headers->get('Location');
            self::assertNotNull($showUrl);
            $url = $showUrl.'/edit';
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            $input = [
                '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
                'version' => $crawler->filter('input[name="version"]')->attr('value'),
                'from' => '2026-01-01', 'to' => '2026-01-31', 'odometerEnd' => '130', 'fuelRemaining' => '30',
                'tripsSubmitted' => '1', 'refuelingsSubmitted' => '1', 'absencesSubmitted' => '1',
                'trips' => $this->postedTrips($crawler), 'refuelings' => [], 'absences' => [],
            ];
        }

        if ('automatic' === $origin) {
            $input['action'] = 'fill_route';
            $input['odometerEnd'] = $edit ? '160' : '130';
            $crawler = $this->client->request('POST', $url, $input);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('.form-alert'));
            $trips = $this->postedTrips($crawler);
            $automaticIndexes = array_keys(array_filter($trips, static fn (array $trip): bool => 'automatic' === $trip['origin']));
            self::assertCount(2, $automaticIndexes);
            [$first, $second] = $automaticIndexes;
            self::assertSame($trips[$first]['pairId'], $trips[$second]['pairId']);
            foreach ($automaticIndexes as $index) {
                self::assertTrue(Uuid::isValid($trips[$index]['id']));
                self::assertSame([$trips[$index]['date'], $trips[$index]['distance'], $trips[$index]['country'], $trips[$index]['city']],
                    [$trips[$index]['originalDate'], $trips[$index]['originalDistance'], $trips[$index]['originalCountry'], $trips[$index]['originalCity']]);
            }

            // Неподменённая пара полностью заменяется при повторном заполнении.
            $input['trips'] = $trips;
            $crawler = $this->client->request('POST', $url, $input);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('.form-alert'));
            self::assertSame([], array_values(array_intersect([$trips[$first]['id'], $trips[$second]['id']], array_column($this->postedTrips($crawler), 'id'))));
            $trips[$first]['id'] = $foreignId;
            $input['trips'] = $trips;
        } else {
            $input['odometerEnd'] = $edit ? '145' : '115';
            $input['trips'][] = [
                'id' => $foreignId, 'date' => '2026-01-20', 'distance' => '15', 'country' => 'Россия', 'city' => 'Подставленная точка', 'cityType' => 'city',
                'street' => 'Тестовая', 'house' => '1', 'enterprise' => 'Подставленное предприятие', 'origin' => 'manual',
                'originalDate' => '2026-01-20', 'originalDistance' => '15', 'originalCountry' => 'Россия', 'originalCity' => 'Подставленная точка', 'originalCityType' => 'city',
                'originalStreet' => 'Тестовая', 'originalHouse' => '1', 'originalEnterprise' => 'Подставленное предприятие',
            ];
            $control = $input;
            $control['action'] = 'fill_route';
            $controlId = (string) Uuid::v7();
            $control['trips'][array_key_last($control['trips'])]['id'] = $controlId;
            $crawler = $this->client->request('POST', $url, $control);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('.form-alert'));
            self::assertContains($controlId, array_column($this->postedTrips($crawler), 'id'));
        }
        if ($fill) {
            $input['action'] = 'fill_route';
        } else {
            unset($input['action']);
        }

        $tripsBefore = $this->connection->fetchAllAssociative('SELECT * FROM pg_temp.waybills_route_log ORDER BY id');
        $draftsBefore = $this->connection->fetchAllAssociative('SELECT * FROM pg_temp.waybills_waybill ORDER BY id');
        $vehiclesBefore = $this->connection->fetchAllAssociative('SELECT * FROM pg_temp.waybills_vehicle ORDER BY id');
        $driversBefore = $this->connection->fetchAllAssociative('SELECT * FROM pg_temp.waybills_driver_profile ORDER BY id');
        self::assertCount($edit ? 3 : 1, $tripsBefore);
        self::assertCount($edit ? 2 : 1, $draftsBefore);

        $crawler = $this->client->request('POST', $url, $input);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Поездка не принадлежит этому черновику.', trim($crawler->filter('.form-alert-title + div')->text()));
        foreach (['Секретная модель', 'B124CC77', 'Другой водитель', 'Чужая база', 'Секретный склад'] as $foreignData) {
            self::assertStringNotContainsString($foreignData, (string) $this->client->getResponse()->getContent());
        }
        self::assertSame($tripsBefore, $this->connection->fetchAllAssociative('SELECT * FROM pg_temp.waybills_route_log ORDER BY id'));
        self::assertSame($draftsBefore, $this->connection->fetchAllAssociative('SELECT * FROM pg_temp.waybills_waybill ORDER BY id'));
        self::assertSame($vehiclesBefore, $this->connection->fetchAllAssociative('SELECT * FROM pg_temp.waybills_vehicle ORDER BY id'));
        self::assertSame($driversBefore, $this->connection->fetchAllAssociative('SELECT * FROM pg_temp.waybills_driver_profile ORDER BY id'));
    }

    /** @return iterable<string, array{bool, bool, string}> */
    public static function foreignTripRequests(): iterable
    {
        yield 'create manual' => [false, false, 'manual'];
        yield 'create automatic' => [false, false, 'automatic'];
        yield 'update manual' => [true, false, 'manual'];
        yield 'update automatic' => [true, false, 'automatic'];
        yield 'fill create manual' => [false, true, 'manual'];
        yield 'fill create removable automatic pair' => [false, true, 'automatic'];
        yield 'fill edit manual' => [true, true, 'manual'];
        yield 'fill edit removable automatic pair' => [true, true, 'automatic'];
    }

    public function test_deadlock_during_save_is_shown_without_persisting_the_waybill(): void
    {
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['odometerEnd'] = '105';
        $input['trips'] = [['id' => '', 'date' => '2026-01-10', 'distance' => '5', 'country' => 'Россия', 'city' => 'Клиент']];
        $locationsBefore = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_location');
        $numberBefore = $this->connection->fetchOne('SELECT next_waybill_number FROM pg_temp.waybills_driver_profile');
        $this->connection->executeStatement(<<<'SQL'
            CREATE FUNCTION pg_temp.raise_trip_deadlock() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'test deadlock' USING ERRCODE = '40P01';
            END;
            $$
            SQL);
        $this->connection->executeStatement('CREATE TRIGGER trip_deadlock BEFORE INSERT ON pg_temp.waybills_route_log FOR EACH ROW EXECUTE FUNCTION pg_temp.raise_trip_deadlock()');

        $crawler = $this->client->request('POST', $url, $input);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Не удалось сохранить путевой лист из-за одновременного изменения. Попробуйте ещё раз.', trim($crawler->filter('.form-alert-title + div')->text()));
        self::assertSelectorExists('input[name="odometerEnd"][value="105"]');
        self::assertSelectorExists('input[name="trips[0][distance]"][value="5"]');
        self::assertSelectorExists('input[name="trips[0][city]"][value="Клиент"]');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_route_log'));
        self::assertSame($locationsBefore, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_location'));
        self::assertSame($numberBefore, $this->connection->fetchOne('SELECT next_waybill_number FROM pg_temp.waybills_driver_profile'));
    }

    public function test_new_manual_trip_ids_with_original_snapshots_are_saved_on_create_and_edit(): void
    {
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $input = $this->creationInput($crawler);
        $firstId = (string) Uuid::v7();
        $input['odometerEnd'] = '115';
        $input['trips'] = [[
            'id' => $firstId, 'date' => '2026-01-10', 'distance' => '15', 'country' => 'Россия', 'city' => 'Клиент', 'origin' => 'manual',
            'originalDate' => '2026-01-10', 'originalDistance' => '15', 'originalCountry' => 'Россия', 'originalCity' => 'Клиент',
        ]];
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects();
        self::assertSame($firstId, $this->connection->fetchOne('SELECT id FROM pg_temp.waybills_route_log'));
        $showUrl = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($showUrl);

        $crawler = $this->client->request('GET', $showUrl.'/edit');
        self::assertResponseIsSuccessful();
        $input['_token'] = $crawler->filter('input[name="_token"]')->attr('value');
        $input['version'] = $crawler->filter('input[name="version"]')->attr('value');
        $input['odometerEnd'] = '130';
        $input['trips'] = $this->postedTrips($crawler);
        $secondId = (string) Uuid::v7();
        $input['trips'][] = [
            'id' => $secondId, 'date' => '2026-01-11', 'distance' => '15', 'country' => 'Россия', 'city' => 'База', 'origin' => 'manual',
            'originalDate' => '2026-01-11', 'originalDistance' => '15', 'originalCountry' => 'Россия', 'originalCity' => 'База',
        ];
        $this->client->request('POST', $showUrl.'/edit', $input);
        self::assertResponseRedirects($showUrl);
        self::assertSame([$firstId, $secondId], $this->connection->fetchFirstColumn('SELECT id FROM pg_temp.waybills_route_log ORDER BY occurred_at'));
    }

    public function test_new_manual_trips_survive_repeated_fill_and_save_on_create_and_edit(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        $vehicle->driver()->replaceDestinations(new Destination(new Location('Россия', 'Клиент'), 15));
        $em->flush();
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;

        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['action'] = 'fill_route';
        $input['odometerEnd'] = '140';
        $input['trips'] = [['id' => '', 'date' => '2026-01-12', 'distance' => '10', 'country' => 'Россия', 'city' => 'Ручная']];
        $crawler = $this->client->request('POST', $url, $input);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.form-alert'));
        self::assertCount(3, $crawler->filter('#trip-rows [data-waybill-form-target~="tripRow"]'));
        $manual = array_values(array_filter($this->postedTrips($crawler), static fn (array $trip): bool => 'manual' === $trip['origin']));
        self::assertCount(1, $manual);
        self::assertSame('', $manual[0]['id']);

        $input = $this->creationInput($crawler);
        $input['action'] = 'fill_route';
        $input['odometerEnd'] = '140';
        $input['trips'] = $this->postedTrips($crawler);
        $crawler = $this->client->request('POST', $url, $input);
        self::assertResponseIsSuccessful();
        self::assertCount(3, $this->postedTrips($crawler));

        $input = $this->creationInput($crawler);
        $input['trips'] = $this->postedTrips($crawler);
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects();
        $showUrl = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($showUrl);
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_route_log'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_route_log WHERE origin = 'manual'"));

        $crawler = $this->client->request('GET', $showUrl.'/edit');
        $trips = $this->postedTrips($crawler);
        $last = $trips[array_key_last($trips)];
        $trips[] = ['id' => '', 'date' => $last['date'], 'distance' => '5', 'country' => 'Россия', 'city' => 'Дополнительная'];
        $edit = [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'version' => $crawler->filter('input[name="version"]')->attr('value'),
            'from' => '2026-01-01', 'to' => '2026-01-31', 'odometerEnd' => '145', 'fuelRemaining' => '30',
            'tripsSubmitted' => '1', 'refuelingsSubmitted' => '1', 'absencesSubmitted' => '1',
            'trips' => $trips, 'refuelings' => [], 'absences' => [], 'action' => 'fill_route',
        ];
        $crawler = $this->client->request('POST', $showUrl.'/edit', $edit);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.form-alert'));
        self::assertCount(4, $this->postedTrips($crawler));
        $manual = array_values(array_filter($this->postedTrips($crawler), static fn (array $trip): bool => 'manual' === $trip['origin']));
        self::assertCount(2, $manual);
        self::assertSame(1, count(array_filter($manual, static fn (array $trip): bool => '' === $trip['id'])));

        $edit['trips'] = $this->postedTrips($crawler);
        $crawler = $this->client->request('POST', $showUrl.'/edit', $edit);
        self::assertResponseIsSuccessful();
        self::assertCount(4, $this->postedTrips($crawler));
        unset($edit['action']);
        $edit['trips'] = $this->postedTrips($crawler);
        $this->client->request('POST', $showUrl.'/edit', $edit);

        self::assertResponseRedirects($showUrl);
        self::assertSame(4, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_route_log'));
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_route_log WHERE origin = 'manual'"));
        self::assertSame(145, (int) $this->connection->fetchOne('SELECT odometer_end FROM pg_temp.waybills_waybill'));
    }

    public function test_fill_replaces_outdated_automatic_pairs_after_a_later_manual_trip_is_inserted(): void
    {
        $payload = ['country' => 'Россия', 'city' => 'База', 'cityType' => 'city', 'street' => 'Транспортная', 'house' => '10', 'enterprise' => 'ООО База', 'latitude' => '56.8', 'longitude' => '60.6'];
        $this->client->request('POST', '/cabinet/waybill/locations/quick', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertResponseStatusCodeSame(201);
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $baseId = Uuid::fromString(($response['data'] ?? $response)['id']);
        $base = new Location('Россия', new Settlement('База', SettlementType::City), 'Транспортная', '10', 'ООО База', 56.8, 60.6, $baseId);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        (new \ReflectionProperty($vehicle, 'initialLocation'))->setValue($vehicle, $base);
        $vehicle->driver()->changeBase($base);
        $vehicle->driver()->replaceDestinations(new Destination(new Location('Россия', 'Клиент'), 15));
        $em->flush();
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;

        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['action'] = 'fill_route';
        $input['odometerEnd'] = '160';
        $crawler = $this->client->request('POST', $url, $input);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.form-alert'));
        $trips = $this->postedTrips($crawler);
        self::assertCount(4, $trips);
        self::assertSame(['automatic', 'automatic', 'automatic', 'automatic'], array_column($trips, 'origin'));
        self::assertSame($trips[0]['pairId'], $trips[1]['pairId']);
        self::assertSame($trips[2]['pairId'], $trips[3]['pairId']);
        self::assertNotSame($trips[0]['pairId'], $trips[2]['pairId']);
        self::assertSame($trips[0]['date'], $trips[1]['date']);
        self::assertSame($trips[2]['date'], $trips[3]['date']);
        self::assertNotSame($trips[0]['date'], $trips[2]['date']);

        $departureFields = array_flip(['locationId', 'country', 'city', 'cityType', 'street', 'house', 'enterprise', 'originalLatitude', 'originalLongitude']);
        $departure = array_intersect_key($trips[1], $departureFields);
        self::assertSame([
            'locationId' => (string) $baseId, 'country' => 'Россия', 'city' => 'База', 'cityType' => 'city', 'street' => 'Транспортная',
            'house' => '10', 'enterprise' => 'ООО База', 'originalLatitude' => '56.8', 'originalLongitude' => '60.6',
        ], $departure);
        // Первая генерация ограничена январём, поэтому ручная поездка всегда позже обеих пар.
        $manual = [
            'id' => '', 'date' => '2026-02-01', 'distance' => '10', 'country' => 'Россия', 'city' => 'Ручная', 'cityType' => 'city',
            'street' => 'Тестовая', 'house' => '1', 'enterprise' => 'ООО Получатель', 'origin' => 'manual',
        ];
        self::assertLessThan($manual['date'], $trips[2]['date']);
        array_splice($trips, 2, 0, [$manual]);
        $input['to'] = '2026-02-28';
        $input['odometerEnd'] = '170';
        $input['trips'] = $trips;

        for ($fill = 0; $fill < 2; ++$fill) {
            $crawler = $this->client->request('POST', $url, $input);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
            self::assertCount(0, $crawler->filter('.form-alert'));
            $trips = $this->postedTrips($crawler);
            self::assertCount(5, $trips);
            self::assertSame(['automatic', 'automatic', 'automatic', 'automatic', 'manual'], array_column($trips, 'origin'));
            self::assertSame($trips[0]['date'], $trips[1]['date']);
            self::assertSame($trips[2]['date'], $trips[3]['date']);
            self::assertNotSame($trips[0]['date'], $trips[2]['date']);
            self::assertSame($manual, array_intersect_key($trips[4], $manual));
            self::assertSame($departure, array_intersect_key($trips[3], $departureFields));
            $dates = array_column($trips, 'date');
            $sortedDates = $dates;
            sort($sortedDates);
            self::assertSame($sortedDates, $dates);
            self::assertSame(70, array_sum(array_column($trips, 'distance')));
            $input['trips'] = $trips;
        }
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));

        unset($input['action']);
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects();
        $showUrl = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($showUrl);
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_route_log'));
        self::assertSame(4, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_route_log WHERE origin = 'automatic'"));
        $saved = $this->connection->fetchAssociative("SELECT trip.id, trip.occurred_at, trip.distance, trip.location, predecessor.location AS departure FROM pg_temp.waybills_route_log trip JOIN pg_temp.waybills_route_log predecessor ON predecessor.id = trip.previous_id WHERE trip.origin = 'manual'");
        self::assertNotFalse($saved);
        self::assertSame($manual['date'], ExactDateTimeType::parse((string) $saved['occurred_at'])->format('Y-m-d'));
        self::assertSame((int) $manual['distance'], (int) $saved['distance']);
        $location = json_decode($saved['location'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($manual['country'], $location['country']);
        self::assertSame($manual['city'], $location['city']['name']);
        self::assertSame($manual['cityType'], $location['city']['type']);
        self::assertSame($manual['street'], $location['street']);
        self::assertSame($manual['house'], $location['house']);
        self::assertSame($manual['enterprise'], $location['enterprise']);
        self::assertJsonStringEqualsJsonString(json_encode($base, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $saved['departure']);
        self::assertSame(170, (int) $this->connection->fetchOne('SELECT odometer_end FROM pg_temp.waybills_waybill'));

        $crawler = $this->client->request('GET', $showUrl.'/edit');
        $trips = $this->postedTrips($crawler);
        self::assertSame((string) $saved['id'], $trips[4]['id']);
        self::assertLessThan('2026-01-31', $trips[2]['date']);
        $detour = ['id' => '', 'date' => '2026-01-31', 'distance' => '5', 'country' => 'Россия', 'city' => 'Промежуточная', 'origin' => 'manual'];
        $return = ['id' => '', 'date' => '2026-01-31', 'distance' => '5', 'locationId' => (string) $baseId, 'country' => 'Россия', 'city' => 'База', 'cityType' => 'city', 'street' => 'Транспортная', 'house' => '10', 'enterprise' => 'ООО База', 'origin' => 'manual'];
        array_splice($trips, 2, 0, [$detour, $return]);
        $edit = [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'version' => $crawler->filter('input[name="version"]')->attr('value'),
            'from' => '2026-01-01', 'to' => '2026-02-28', 'odometerEnd' => '180', 'fuelRemaining' => '30',
            'tripsSubmitted' => '1', 'refuelingsSubmitted' => '1', 'absencesSubmitted' => '1',
            'trips' => $trips, 'refuelings' => [], 'absences' => [], 'action' => 'fill_route',
        ];
        for ($fill = 0; $fill < 2; ++$fill) {
            $crawler = $this->client->request('POST', $showUrl.'/edit', $edit);
            self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
            self::assertCount(0, $crawler->filter('.form-alert'));
            $trips = $this->postedTrips($crawler);
            self::assertCount(7, $trips);
            self::assertCount(3, array_filter($trips, static fn (array $trip): bool => 'manual' === $trip['origin']));
            self::assertCount(1, array_filter($trips, static fn (array $trip): bool => $trip['id'] === $saved['id']));
            $dates = array_column($trips, 'date');
            $sortedDates = $dates;
            sort($sortedDates);
            self::assertSame($sortedDates, $dates);
            self::assertSame(80, array_sum(array_column($trips, 'distance')));
            $edit['trips'] = $trips;
        }
        unset($edit['action']);
        $this->client->request('POST', $showUrl.'/edit', $edit);
        self::assertResponseRedirects($showUrl);
        $departure = $this->connection->fetchOne('SELECT predecessor.location FROM pg_temp.waybills_route_log trip JOIN pg_temp.waybills_route_log predecessor ON predecessor.id = trip.previous_id WHERE trip.id = ?', [$saved['id']]);
        self::assertIsString($departure);
        self::assertJsonStringEqualsJsonString(json_encode($base, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $departure);
        self::assertSame(180, (int) $this->connection->fetchOne('SELECT odometer_end FROM pg_temp.waybills_waybill'));
    }

    public function test_manual_return_preserves_legacy_initial_location_coordinates(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        (new \ReflectionProperty($vehicle, 'initialLocation'))->setValue($vehicle, new Location('Россия', 'База', latitude: 56.8, longitude: 60.6));
        $em->flush();
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $input = $this->creationInput($crawler);
        $input['odometerEnd'] = '';
        $input['trips'] = [
            ['id' => '', 'date' => '2026-01-10', 'distance' => '15', 'country' => 'Россия', 'city' => 'Завод'],
            ['id' => '', 'date' => '2026-01-10', 'distance' => '15', 'country' => 'Россия', 'city' => 'База'],
        ];
        $this->client->request('POST', $url, $input);

        self::assertResponseRedirects();
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_route_log WHERE location->>'latitude' = '56.8' AND location->>'longitude' = '60.6'"));
    }

    public function test_driver_can_reuse_foreign_vehicle_snapshot_but_not_choose_an_unrelated_foreign_point(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        $baseId = Uuid::v7();
        $base = new Location('Россия', 'База', latitude: 56.8, longitude: 60.6, id: $baseId);
        $foreignId = Uuid::v7();
        $em->persist(new CatalogLocation($baseId, 'other-owner', $base, new \DateTimeImmutable()));
        $em->persist(new CatalogLocation($foreignId, 'other-owner', new Location('Россия', 'Чужая'), new \DateTimeImmutable()));
        (new \ReflectionProperty($vehicle, 'initialLocation'))->setValue($vehicle, $base);
        $em->flush();
        $this->client->request('GET', '/cabinet/waybill/sheets');
        $user = static::getContainer()->get(AuthUserFetcherInterface::class)->getAuthUser();
        self::assertInstanceOf(User::class, $user);
        (new \ReflectionProperty($user, 'roles'))->setValue($user, ['ROLE_USER']);
        static::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $waybill = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31 23:59:59')));
        $catalog = static::getContainer()->get(LocationCatalog::class);
        $date = new \DateTimeImmutable('2026-01-10');
        $known = $catalog->resolveTrips($waybill, null, new TripInput(null, $date, new Destination(new Location('Россия', 'База', id: $baseId), 15)));
        self::assertSame(56.8, $known[0]->destination->location->latitude);
        self::assertSame((string) $baseId, (string) $known[0]->destination->location->id);

        $this->expectException(ForbiddenException::class);
        $catalog->resolveTrips($waybill, null, new TripInput(null, $date, new Destination(new Location('Россия', 'Чужая', id: $foreignId), 15)));
    }

    public function test_repeated_fill_preserves_coordinates_of_legacy_points_without_ids(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        $vehicle->driver()->replaceDestinations(new Destination(new Location('Россия', 'Клиент', latitude: 56.8, longitude: 60.6), 15));
        $em->flush();
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['action'] = 'fill_route';
        $input['odometerEnd'] = '160';
        $crawler = $this->client->request('POST', $url, $input);
        self::assertResponseIsSuccessful();
        $input = $this->creationInput($crawler);
        $input['action'] = 'fill_route';
        $input['trips'] = $this->postedTrips($crawler);
        $crawler = $this->client->request('POST', $url, $input);
        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('#trip-rows input[name$="[origin]"][value="automatic"]'));

        $input = $this->creationInput($crawler);
        $input['trips'] = $this->postedTrips($crawler);
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects();
        self::assertSame(4, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_route_log WHERE origin = 'automatic'"));
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_route_log WHERE location->>'latitude' = '56.8' AND location->>'longitude' = '60.6'"));
    }

    public function test_monthly_catalog_route_survives_repeated_fill_and_save_with_44_trips(): void
    {
        $payload = ['country' => 'Россия', 'city' => 'Клиент', 'cityType' => 'city', 'latitude' => '56.8', 'longitude' => '60.6'];
        $this->client->request('POST', '/cabinet/waybill/locations/quick', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $locationId = Uuid::fromString(($response['data'] ?? $response)['id']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        $vehicle->driver()->replaceDestinations(new Destination(new Location('Россия', new Settlement('Клиент', SettlementType::City), latitude: 56.8, longitude: 60.6, id: $locationId), 15));
        $em->flush();
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;

        $crawler = $this->client->request('GET', $url);
        $input = array_merge($this->creationInput($crawler), [
            'from' => '2026-04-01', 'to' => '2026-04-30', 'odometerEnd' => '760', 'action' => 'fill_route',
        ]);
        $crawler = $this->client->request('POST', $url, $input);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
        self::assertCount(44, $this->postedTrips($crawler));

        $input = array_merge($this->creationInput($crawler), [
            'from' => '2026-04-01', 'to' => '2026-04-30', 'odometerEnd' => '760', 'action' => 'fill_route',
            'trips' => $this->postedTrips($crawler),
        ]);
        $crawler = $this->client->request('POST', $url, $input);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
        self::assertCount(0, $crawler->filter('.form-alert'));
        self::assertCount(44, $this->postedTrips($crawler));

        $input = array_merge($this->creationInput($crawler), [
            'from' => '2026-04-01', 'to' => '2026-04-30', 'odometerEnd' => '760',
            'trips' => $this->postedTrips($crawler),
        ]);
        $this->client->request('POST', $url, $input);

        self::assertResponseRedirects();
        self::assertSame(44, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_route_log'));
        self::assertSame(760, (int) $this->connection->fetchOne('SELECT odometer_end FROM pg_temp.waybills_waybill'));
    }

    public function test_unchanged_automatic_return_uses_settings_snapshot_instead_of_predecessor_coordinates(): void
    {
        $payload = ['country' => 'Россия', 'city' => 'База', 'cityType' => 'city', 'latitude' => '56.8', 'longitude' => '60.6'];
        $this->client->request('POST', '/cabinet/waybill/locations/quick', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $baseId = Uuid::fromString(($response['data'] ?? $response)['id']);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        $settlement = new Settlement('База', SettlementType::City);
        $previousBase = new Location('Россия', $settlement, id: $baseId);
        $configuredBase = new Location('Россия', $settlement, latitude: 56.8, longitude: 60.6, id: $baseId);
        $previous = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2025-12-31'), new Destination($previousBase, 5), RouteLogOrigin::Manual);
        $em->persist($previous);
        $vehicle->driver()->changeBase($configuredBase);
        $vehicle->driver()->replaceDestinations(new Destination(new Location('Россия', 'Клиент'), 15));
        $em->flush();
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;

        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['action'] = 'fill_route';
        $input['odometerEnd'] = '130';
        $crawler = $this->client->request('POST', $url, $input);
        self::assertResponseIsSuccessful();
        $trips = $this->postedTrips($crawler);
        self::assertCount(2, $trips);
        self::assertSame((string) $baseId, $trips[1]['locationId']);
        self::assertSame('56.8', $trips[1]['originalLatitude']);

        $input = $this->creationInput($crawler);
        $input['action'] = 'fill_route';
        $input['odometerEnd'] = '130';
        $input['trips'] = $trips;
        $crawler = $this->client->request('POST', $url, $input);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.form-alert'));
        self::assertCount(2, $crawler->filter('#trip-rows input[name$="[origin]"][value="automatic"]'));

        $input = $this->creationInput($crawler);
        $input['trips'] = $this->postedTrips($crawler);
        $input['trips'][1]['latitude'] = '1.1';
        $input['trips'][1]['longitude'] = '2.2';
        $this->client->request('POST', $url, $input);

        self::assertResponseRedirects();
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_route_log WHERE origin = 'automatic'"));
        $saved = json_decode((string) $this->connection->fetchOne('SELECT location FROM pg_temp.waybills_route_log WHERE location->>\'id\' = ? AND draft_waybill_id IS NOT NULL', [(string) $baseId]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(56.8, $saved['latitude']);
        self::assertSame(60.6, $saved['longitude']);
    }

    public function test_converted_unsaved_pair_restores_coordinates_from_settings_not_post(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        $vehicle->driver()->replaceDestinations(new Destination(new Location('Россия', 'Клиент', latitude: 56.8, longitude: 60.6), 15));
        $em->flush();
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['action'] = 'fill_route';
        $input['odometerEnd'] = '130';
        $crawler = $this->client->request('POST', $url, $input);
        self::assertResponseIsSuccessful();

        $input = $this->creationInput($crawler);
        $input['action'] = 'fill_route';
        $input['odometerEnd'] = '131';
        $input['trips'] = $this->postedTrips($crawler);
        $input['trips'][0]['distance'] = '16';
        $crawler = $this->client->request('POST', $url, $input);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.form-alert'));
        $input = $this->creationInput($crawler);
        $input['trips'] = $this->postedTrips($crawler);
        self::assertSame(['manual', 'manual'], array_column($input['trips'], 'origin'));
        $input['trips'][0]['latitude'] = '1.1';
        $input['trips'][0]['longitude'] = '2.2';
        $input['trips'][0]['originalLatitude'] = '1.1';
        $input['trips'][0]['originalLongitude'] = '2.2';

        $this->client->request('POST', $url, $input);

        self::assertResponseRedirects();
        self::assertSame(2, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_route_log WHERE origin = 'manual'"));
        $saved = json_decode((string) $this->connection->fetchOne("SELECT location FROM pg_temp.waybills_route_log WHERE location->'city'->>'name' = 'Клиент'"), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(56.8, $saved['latitude']);
        self::assertSame(60.6, $saved['longitude']);
    }

    public function test_return_reuses_only_actual_predecessor_snapshot_after_driver_change(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        $baseId = Uuid::v7();
        $foreignId = Uuid::v7();
        $base = new Location('Россия', 'Фактическая база', latitude: 56.8, longitude: 60.6, id: $baseId);
        $em->persist(new CatalogLocation($baseId, 'previous-driver', $base, new \DateTimeImmutable()));
        $em->persist(new CatalogLocation($foreignId, 'previous-driver', new Location('Россия', 'Чужая'), new \DateTimeImmutable()));
        $previous = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2025-12-31'), new Destination($base, 5), RouteLogOrigin::Manual);
        $em->persist($previous);
        $vehicle->driver()->changeBase($base);
        $vehicle->driver()->replaceDestinations(new Destination(new Location('Россия', 'Клиент'), 15));
        $em->flush();
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;

        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $input = $this->creationInput($crawler);
        $input['action'] = 'fill_route';
        $input['odometerEnd'] = '130';
        $crawler = $this->client->request('POST', $url, $input);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.form-alert'));
        $input = $this->creationInput($crawler);
        $input['trips'] = $this->postedTrips($crawler);
        self::assertSame((string) $baseId, $input['trips'][1]['locationId']);
        $this->client->request('POST', $url, $input);

        self::assertResponseRedirects();
        $saved = json_decode((string) $this->connection->fetchOne('SELECT location FROM pg_temp.waybills_route_log WHERE location->>\'id\' = ? AND draft_waybill_id IS NOT NULL', [(string) $baseId]), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(56.8, $saved['latitude']);
        self::assertSame(60.6, $saved['longitude']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_location WHERE id = ?', [(string) $baseId]));

        $this->client->request('GET', '/cabinet/waybill/sheets');
        $user = static::getContainer()->get(AuthUserFetcherInterface::class)->getAuthUser();
        self::assertInstanceOf(User::class, $user);
        (new \ReflectionProperty($user, 'roles'))->setValue($user, ['ROLE_USER']);
        static::getContainer()->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        $draft = Waybill::createDraft(Uuid::v7(), 2, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-02-01'), new \DateTimeImmutable('2026-02-28')));
        $catalog = static::getContainer()->get(LocationCatalog::class);
        $known = $catalog->resolveTrips(
            $draft,
            $previous,
            new TripInput(null, new \DateTimeImmutable('2026-02-10'), new Destination(new Location('Россия', 'Фактическая база', id: $baseId), 15)),
        );
        self::assertSame(56.8, $known[0]->destination->location->latitude);

        $this->expectException(ForbiddenException::class);
        $catalog->resolveTrips(
            $draft,
            $previous,
            new TripInput(null, new \DateTimeImmutable('2026-02-10'), new Destination(new Location('Россия', 'Чужая', id: $foreignId), 15)),
        );
    }

    public function test_fill_button_skips_dates_before_vehicle_history(): void
    {
        $this->connection->executeStatement('UPDATE pg_temp.waybills_vehicle SET initial_at = ? WHERE id = ?', ['2026-01-20 15:00:00', $this->vehicleId]);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        $vehicle->driver()->replaceDestinations(new Destination(new Location('Россия', 'Клиент'), 15));
        $em->flush();
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;

        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['action'] = 'fill_route';
        $input['odometerEnd'] = '130';
        $crawler = $this->client->request('POST', $url, $input);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
        self::assertCount(0, $crawler->filter('.form-alert'));
        $trips = $this->postedTrips($crawler);
        self::assertCount(2, $trips);
        self::assertCount(1, array_unique(array_column($trips, 'date')));
        self::assertGreaterThanOrEqual('2026-01-20', $trips[0]['date']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));
    }

    public function test_quick_location_api_keeps_explicit_coordinate_values_distinct(): void
    {
        $payload = ['country' => 'Россия', 'city' => 'Екатеринбург', 'cityType' => 'city', 'street' => 'Ирбитская', 'house' => '8', 'latitude' => '56.8', 'longitude' => '60.6'];
        $this->client->request('POST', '/cabinet/waybill/locations/quick', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $first = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $first = $first['data'] ?? $first;
        $payload['latitude'] = '';
        $payload['longitude'] = '';
        $this->client->request('POST', '/cabinet/waybill/locations/quick', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $second = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $second = $second['data'] ?? $second;

        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(56.8, (float) $this->connection->fetchOne('SELECT latitude FROM pg_temp.waybills_location WHERE id = ?', [$first['id']]));
        self::assertNull($this->connection->fetchOne('SELECT latitude FROM pg_temp.waybills_location WHERE id = ?', [$second['id']]));
    }

    public function test_explicit_trip_coordinate_changes_are_not_replaced_with_old_values(): void
    {
        $payload = ['country' => 'Россия', 'city' => 'Клиент', 'cityType' => 'city', 'latitude' => '56.8', 'longitude' => '60.6'];
        $this->client->request('POST', '/cabinet/waybill/locations/quick', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $locationId = Uuid::fromString(($response['data'] ?? $response)['id']);
        $vehicle = static::getContainer()->get(EntityManagerInterface::class)->find(Vehicle::class, $this->vehicleId);
        $waybill = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval(new \DateTimeImmutable('2026-01-01'), new \DateTimeImmutable('2026-01-31 23:59:59')));
        $city = new Settlement('Клиент', SettlementType::City);
        $date = new \DateTimeImmutable('2026-01-10');
        $trip = new RouteLog(Uuid::v7(), $vehicle, null, $date, new Destination(new Location('Россия', $city, latitude: 56.8, longitude: 60.6, id: $locationId), 15), RouteLogOrigin::Manual);
        $waybill->setTrips($trip);
        $catalog = static::getContainer()->get(LocationCatalog::class);

        $changed = $catalog->resolveTrips($waybill, null, new TripInput($trip->id, $date, new Destination(new Location('Россия', $city, latitude: 57.0, longitude: 61.0, id: $locationId), 15)));
        self::assertSame(57.0, $changed[0]->destination->location->latitude);
        $cleared = $catalog->resolveTrips($waybill, null, new TripInput($trip->id, $date, new Destination(new Location('Россия', $city, id: $locationId), 15)));
        self::assertNull($cleared[0]->destination->location->latitude);
    }

    public function test_vehicle_creation_links_initial_location_to_catalog(): void
    {
        $payload = ['country' => 'Россия', 'city' => 'Екатеринбург', 'cityType' => 'city', 'street' => 'Ирбитская', 'house' => '8', 'enterprise' => '', 'latitude' => '56.8', 'longitude' => '60.6'];
        $this->client->request('POST', '/cabinet/waybill/locations/quick', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $location = $response['data'] ?? $response;
        $url = '/cabinet/waybill/vehicle/new';
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="initialCityType"] option[value="city"]');
        self::assertSelectorNotExists('input[name="initialLatitude"]');
        self::assertSelectorNotExists('input[name="initialLongitude"]');
        self::assertSelectorNotExists('[data-location-create-modal-target="latitude"]');
        self::assertSelectorNotExists('[data-location-create-modal-target="longitude"]');

        $input = [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'model' => 'Новый автомобиль', 'registrationNumber' => 'B123CC77', 'fuelTypes' => ['АИ-95'], 'defaultFuelType' => 'АИ-95', 'consumptionRate' => '',
            'initialAt' => '2026-01-02T10:30', 'initialLocationId' => $location['id'], 'initialCountry' => 'Россия', 'initialCity' => 'Екатеринбург', 'initialCityType' => 'city',
            'initialStreet' => 'Ирбитская', 'initialHouse' => '8', 'initialEnterprise' => '',
            'initialOdometer' => '0', 'initialFuelRemaining' => '10',
        ];
        $this->client->request('POST', $url, $input);

        self::assertResponseRedirects('/cabinet/waybill/vehicle');
        $initialLocation = json_decode((string) $this->connection->fetchOne("SELECT initial_location FROM pg_temp.waybills_vehicle WHERE registration_number = 'b123cc77'"), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($location['id'], $initialLocation['id']);
        self::assertSame(['name' => 'Екатеринбург', 'type' => 'city'], $initialLocation['city']);
        self::assertSame(56.8, $initialLocation['latitude']);
        self::assertSame(60.6, $initialLocation['longitude']);
        $input['registrationNumber'] = 'B124CC77';
        $input['initialLocationId'] = '';
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects('/cabinet/waybill/vehicle');
        $typedLocation = json_decode((string) $this->connection->fetchOne("SELECT initial_location FROM pg_temp.waybills_vehicle WHERE registration_number = 'b124cc77'"), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($location['id'], $typedLocation['id']);
        self::assertSame(56.8, $typedLocation['latitude']);
    }

    public function test_profile_preserves_coordinates_of_legacy_base_without_id(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        $vehicle->driver()->changeBase(new Location('Россия', 'База', latitude: 56.8, longitude: 60.6));
        $em->flush();
        $url = '/cabinet/waybills/profile/edit';
        $crawler = $this->client->request('GET', $url);
        $this->client->request('POST', $url, [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'fullName' => 'Водитель', 'licenseNumber' => '1234567890', 'licenseIssuedAt' => '2020-01-01', 'snils' => '11223344595',
            'base' => ['id' => '', 'country' => 'Россия', 'city' => 'База', 'cityType' => '', 'street' => '', 'house' => '', 'enterprise' => ''],
            'destinations' => [],
        ]);
        self::assertResponseRedirects('/cabinet/waybills/profile');
        $settings = json_decode((string) $this->connection->fetchOne('SELECT route_settings FROM pg_temp.waybills_driver_profile'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(56.8, $settings['base']['latitude']);
        self::assertSame(60.6, $settings['base']['longitude']);
    }

    public function test_profile_saves_and_corrects_catalog_location(): void
    {
        $url = '/cabinet/waybills/profile/edit';
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('select[name="base[cityType]"] option[value="city"]');
        $input = [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'fullName' => 'Водитель', 'licenseNumber' => '1234567890', 'licenseIssuedAt' => '2020-01-01', 'snils' => '11223344595',
            'base' => [
                'id' => '', 'country' => 'Россия', 'city' => 'Екаетринбург', 'cityType' => 'city', 'street' => 'Ирбитская', 'house' => '8',
                'enterprise' => '',
            ],
            'destinations' => [],
        ];
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects('/cabinet/waybills/profile');
        $settings = json_decode((string) $this->connection->fetchOne('SELECT route_settings FROM pg_temp.waybills_driver_profile'), true, flags: JSON_THROW_ON_ERROR);
        $locationId = $settings['base']['id'];
        self::assertTrue(Uuid::isValid($locationId));
        self::assertSame(['name' => 'Екаетринбург', 'type' => 'city'], $settings['base']['city']);

        $input['base']['id'] = $locationId;
        $this->connection->executeStatement('UPDATE pg_temp.waybills_location SET latitude = 56.8, longitude = 60.6 WHERE id = ?', [$locationId]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects('/cabinet/waybills/profile');
        $settings = json_decode((string) $this->connection->fetchOne('SELECT route_settings FROM pg_temp.waybills_driver_profile'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(56.8, $settings['base']['latitude']);
        self::assertSame(60.6, $settings['base']['longitude']);
        self::assertSame(56.8, (float) $this->connection->fetchOne('SELECT latitude FROM pg_temp.waybills_location WHERE id = ?', [$locationId]));
        $input['base']['city'] = 'Екатеринбург';
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects('/cabinet/waybills/profile');
        $settings = json_decode((string) $this->connection->fetchOne('SELECT route_settings FROM pg_temp.waybills_driver_profile'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($locationId, $settings['base']['id']);
        self::assertSame(['name' => 'Екатеринбург', 'type' => 'city'], $settings['base']['city']);
        self::assertSame('Екатеринбург', $this->connection->fetchOne('SELECT city FROM pg_temp.waybills_location WHERE id = ?', [$locationId]));
        self::assertNull($this->connection->fetchOne('SELECT latitude FROM pg_temp.waybills_location WHERE id = ?', [$locationId]));
        self::assertNull($this->connection->fetchOne('SELECT longitude FROM pg_temp.waybills_location WHERE id = ?', [$locationId]));
    }

    public function test_fill_uses_location_identity_after_address_correction(): void
    {
        $locationId = Uuid::v7();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        $vehicle->driver()->changeBase(new Location('Россия', 'Екаетринбург', id: $locationId));
        $vehicle->driver()->replaceDestinations(new Destination(new Location('Россия', 'Клиент'), 15));
        $em->flush();
        $initialLocation = new Location('Россия', 'Екатеринбург', id: $locationId);
        $this->connection->update('pg_temp.waybills_vehicle', ['initial_location' => json_encode($initialLocation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], ['id' => $this->vehicleId]);
        $em->clear();
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;

        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['action'] = 'fill_route';
        $input['odometerEnd'] = '130';
        $crawler = $this->client->request('POST', $url, $input);

        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
        self::assertCount(0, $crawler->filter('.form-alert'));
        self::assertCount(2, $crawler->filter('#trip-rows [data-waybill-form-target~="tripRow"]'));
        self::assertSame((string) $locationId, $this->postedTrips($crawler)[1]['locationId']);
    }

    public function test_location_catalog_quick_create_and_suggest(): void
    {
        $payload = [
            'country' => 'Россия', 'city' => 'Березовский', 'cityType' => 'city', 'street' => 'Транспортников', 'house' => '46',
            'enterprise' => 'ООО ЗМК Наста', 'latitude' => 56.8, 'longitude' => 60.6,
        ];
        $this->client->request('POST', '/cabinet/waybill/locations/quick', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $created = $response['data'] ?? $response;
        self::assertTrue(Uuid::isValid($created['id']));
        self::assertSame('Березовский', $created['city']);
        self::assertSame('city', $created['cityType']);
        self::assertSame('ООО ЗМК Наста', $created['enterprise']);
        self::assertSame(56.8, $created['latitude']);
        self::assertSame(60.6, $created['longitude']);
        self::assertSame('Россия, г. Березовский, Транспортников, 46, ООО ЗМК Наста', $created['title']);
        $stored = $this->connection->fetchAssociative('SELECT owner_id, created_at, updated_at FROM pg_temp.waybills_location WHERE id = ?', [$created['id']]);
        self::assertNotEmpty($stored['owner_id']);
        self::assertNotNull($stored['created_at']);
        self::assertNotNull($stored['updated_at']);

        $this->client->request('GET', '/cabinet/waybill/locations/suggest?q=Берез');
        self::assertResponseIsSuccessful();
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $items = ($response['data'] ?? $response)['items'];
        self::assertCount(1, $items);
        self::assertSame($created['id'], $items[0]['id']);
        self::assertSame($created['title'], $items[0]['title']);

        $this->client->request('POST', '/cabinet/waybill/locations/quick', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['city' => 'Без типа'], JSON_THROW_ON_ERROR));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());

        $other = new User(new Email('location-other@example.com'));
        (new \ReflectionProperty($other, 'isActive'))->setValue($other, true);
        $this->connection->executeStatement("INSERT INTO pg_temp.user_user (ulid, email, password, roles, is_active) VALUES (?, ?, 'test', '[\"ROLE_USER\"]', true)", [$other->getUlid(), 'location-other@example.com']);
        $this->client->loginUser($other);
        $this->client->request('GET', '/cabinet/waybill/locations/suggest?q=Берез');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', '/cabinet/waybill/locations/quick', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['id' => $created['id'], ...$payload], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        self::assertResponseStatusCodeSame(403);
    }

    public function test_location_suggest_searches_all_fields_and_loads_ten_items_per_page(): void
    {
        for ($house = 1; $house <= 11; ++$house) {
            $payload = [
                'country' => 'Россия', 'city' => 'Екатеринбург', 'cityType' => 'city', 'street' => 'Ирбитская', 'house' => (string) $house,
                'enterprise' => 'ООО Наста', 'latitude' => 56.8, 'longitude' => 60.6,
            ];
            $this->client->request('POST', '/cabinet/waybill/locations/quick', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            self::assertSame(201, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        }

        $firstPage = $this->locationSuggestions('еКаТеР');
        self::assertCount(10, $firstPage['items']);
        self::assertSame(1, $firstPage['page']);
        self::assertTrue($firstPage['hasMore']);

        $secondPage = $this->locationSuggestions('еКаТеР', 2);
        self::assertCount(1, $secondPage['items']);
        self::assertSame(2, $secondPage['page']);
        self::assertFalse($secondPage['hasMore']);
        $ids = array_merge(array_column($firstPage['items'], 'id'), array_column($secondPage['items'], 'id'));
        self::assertCount(11, array_unique($ids));

        foreach (['рОсСи', 'иРбИтСк', 'НаСтА', 'city', '56.8', '60.6'] as $query) {
            $result = $this->locationSuggestions($query);
            self::assertCount(10, $result['items'], $query);
            self::assertTrue($result['hasMore'], $query);
        }

        $house = $this->locationSuggestions('11');
        self::assertCount(1, $house['items']);
        self::assertSame('11', $house['items'][0]['house']);
        self::assertFalse($house['hasMore']);
    }

    public function test_user_creates_and_edits_draft_without_a_planner(): void
    {
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), substr(strip_tags($this->client->getResponse()->getContent()), 0, 500));
        $this->client->request('POST', $url, $this->creationInput($crawler));
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
        $showUrl = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($showUrl);
        $editUrl = $showUrl.'/edit';
        $crawler = $this->client->request('GET', $editUrl);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
        $this->assertCompactWaybillForm($crawler);
        self::assertSelectorExists('input[name="version"]');
        self::assertSelectorNotExists('input[name="initialOdometer"]');
        self::assertSelectorNotExists('input[name="initialFuel"]');
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $input = [
            '_token' => $token, 'version' => '1', 'from' => '2026-01-01', 'to' => '2026-01-31',
            'odometerEnd' => '', 'fuelRemaining' => '30', 'tripsSubmitted' => '1', 'refuelingsSubmitted' => '1', 'absencesSubmitted' => '1',
            'refuelings' => [['id' => '', 'date' => '2026-01-10T12:00', 'fuelType' => 'АИ-95', 'quantity' => '12.5']],
            'trips' => [['id' => '', 'date' => '2026-01-10T12:00', 'distance' => '5', 'country' => 'Россия', 'city' => 'Москва', 'street' => '', 'house' => '', 'enterprise' => 'Склад']],
        ];
        $this->client->request('POST', $editUrl, $input);
        self::assertSame(302, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
        $this->client->followRedirect();
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->headers->get('X-Debug-Exception'));
        self::assertSelectorTextContains('body', 'Склад');
        self::assertSelectorTextContains('body', '12.5');
        self::assertSame('30.00000000000000000001', $this->connection->fetchOne('SELECT fuel_start::text FROM pg_temp.waybills_waybill'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_route_log WHERE draft_waybill_id IS NOT NULL'));
        self::assertSame('30.0', $this->connection->fetchOne("SELECT fuel->>'remaining' FROM pg_temp.waybills_waybill"));

        $input['refuelings'][0]['quantity'] = '88';
        $this->client->request('POST', $editUrl, $input);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-alert', 'изменился');
        self::assertSelectorExists('input[name="refuelings[0][quantity]"][value="88"]');
        self::assertEquals('12.5', $this->connection->fetchOne('SELECT amount::text FROM pg_temp.waybills_fuel_operation'));

        $crawler = $this->client->request('GET', $editUrl);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#trip-rows details[open] summary [data-role="trip-date"]');
        self::assertSelectorExists('#trip-rows [data-waybill-form-target~="tripRow"] .bi-circle-fill.text-primary');
        self::assertSelectorExists('#trip-rows [data-waybill-form-target~="tripRow"] [data-role="route-line"][hidden]');
        self::assertSelectorTextContains('#trip-rows details summary [data-role="destination"]', 'Москва');
        self::assertSelectorExists('#trip-rows details input[name="trips[0][distance]"]');
        self::assertSelectorExists('#trip-rows details + button.position-absolute[data-waybill-form-kind-param="trip"]');
        self::assertSelectorNotExists('#trip-rows details button[data-waybill-form-kind-param="trip"]');
        self::assertSelectorExists('template[data-waybill-form-target="tripTemplate"] details[open] input[name="trips[__i__][city]"]');
        self::assertSelectorExists('#trip-rows [data-role="trip-insertion"][hidden] button.w-100[data-action="click->waybill-form#insert"]');
        self::assertSelectorTextContains('#trip-rows [data-role="trip-insertion"] button', 'Добавить поездку');
        self::assertSelectorExists('#sec-trips button.w-100[data-waybill-form-kind-param="trip"]');
        self::assertSelectorExists('#sec-trips button[name="action"][value="fill_route"].btn-soft-primary i.bi-magic');
        $input['version'] = $crawler->filter('input[name="version"]')->attr('value');
        $input['trips'][0]['id'] = $crawler->filter('input[name="trips[0][id]"]')->attr('value');
        $input['refuelings'][0]['id'] = $crawler->filter('input[name="refuelings[0][id]"]')->attr('value');
        $input['trips'][0]['distance'] = 'not-a-number';
        $input['refuelings'][0]['quantity'] = '77';
        $this->client->request('POST', $editUrl, $input);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-alert', 'целое расстояние');
        self::assertSelectorExists('input[name="refuelings[0][quantity]"][value="77"]');
        self::assertSelectorExists('input[name="trips[0][enterprise]"][value="Склад"]');
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT distance FROM pg_temp.waybills_route_log'));

        $input['trips'][0]['distance'] = '6';
        $this->client->request('POST', $editUrl, $input);
        self::assertResponseRedirects();
        self::assertSame($input['trips'][0]['id'], $this->connection->fetchOne('SELECT id FROM pg_temp.waybills_route_log'));
        self::assertSame(6, (int) $this->connection->fetchOne('SELECT distance FROM pg_temp.waybills_route_log'));
        self::assertEquals('77', $this->connection->fetchOne('SELECT amount::text FROM pg_temp.waybills_fuel_operation'));

        unset($input['tripsSubmitted']);
        $this->client->request('POST', $editUrl, $input);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-alert', 'Список поездок не был передан');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_route_log'));

        $input['tripsSubmitted'] = '1';
        $input['_token'] = 'invalid';
        $input['refuelings'][0]['quantity'] = '99';
        $this->client->request('POST', $editUrl, $input);
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorExists('input[name="refuelings[0][quantity]"][value="99"]');
        self::assertEquals('77', $this->connection->fetchOne('SELECT amount::text FROM pg_temp.waybills_fuel_operation'));
    }

    public function test_create_and_empty_edit_forms_use_the_actual_predecessor_location(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        $previous = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2025-12-31'), new Destination(new Location('Россия', 'Фактическая'), 5), RouteLogOrigin::Manual);
        $em->persist($previous);
        $em->flush();
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;

        $crawler = $this->client->request('GET', $url);
        $location = json_decode($crawler->filter('#waybill-form')->attr('data-waybill-form-initial-location-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Фактическая', $location['city']);
        $input = $this->creationInput($crawler);
        $input['odometerEnd'] = '100';
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects();
        $showUrl = $this->client->getResponse()->headers->get('Location');

        $crawler = $this->client->request('GET', $showUrl.'/edit');
        $location = json_decode($crawler->filter('#waybill-form')->attr('data-waybill-form-initial-location-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Фактическая', $location['city']);
    }

    public function test_another_administrator_cannot_create_a_draft_for_the_current_driver(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vehicle = $em->find(Vehicle::class, $this->vehicleId);
        $previous = new RouteLog(Uuid::v7(), $vehicle, null, new \DateTimeImmutable('2025-12-31'), new Destination(new Location('Россия', 'Скрытая'), 5), RouteLogOrigin::Manual);
        $em->persist($previous);
        $em->flush();
        $other = new User(new Email('other-waybill-web@example.com'));
        (new \ReflectionProperty($other, 'roles'))->setValue($other, ['ROLE_ADMIN']);
        (new \ReflectionProperty($other, 'isActive'))->setValue($other, true);
        $this->connection->executeStatement("INSERT INTO pg_temp.user_user (ulid, email, password, roles, is_active) VALUES (?, ?, 'test', '[\"ROLE_ADMIN\"]', true)", [$other->getUlid(), 'other-waybill-web@example.com']);
        $this->client->loginUser($other);
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $location = json_decode($crawler->filter('#waybill-form')->attr('data-waybill-form-initial-location-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('База', $location['city']);
        $this->client->request('POST', $url, $this->creationInput($crawler));

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT next_waybill_number FROM pg_temp.waybills_driver_profile'));
    }

    public function test_create_form_keeps_all_data_and_saves_trips_atomically(): void
    {
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        $this->assertCompactWaybillForm($crawler);
        self::assertSelectorExists('#sec-trips input[name="odometerEnd"][value="100"]');
        self::assertSelectorExists('input[name="initialOdometer"]');
        self::assertSelectorExists('input[name="initialFuel"]');
        self::assertSelectorNotExists('input[name="version"]');
        self::assertSelectorNotExists('#sec-refuel input[name="refueled"]');
        $input = $this->creationInput($crawler);
        $input['refuelings'] = [['id' => '', 'date' => '2026-01-10T12:00', 'fuelType' => 'АИ-95', 'quantity' => '12.5']];
        $input['trips'] = [
            ['id' => '', 'date' => '2026-01-10T12:00', 'distance' => '15', 'country' => 'Россия', 'city' => 'Первая', 'enterprise' => 'Склад'],
        ];
        $input['trips'][] = ['id' => '', 'date' => '2026-01-11T12:00', 'distance' => '200', 'country' => 'Россия', 'city' => 'Вторая'];

        $missing = $input;
        unset($missing['tripsSubmitted']);
        $this->client->request('POST', $url, $missing);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-alert', 'Список поездок не был передан');
        self::assertSelectorExists('input[name="from"][value="2026-01-01"]');
        self::assertSelectorExists('input[name="refuelings[0][quantity]"][value="12.5"]');
        self::assertSelectorExists('input[name="trips[1][distance]"][value="200"]');

        $token = $input['_token'];
        $input['_token'] = 'invalid';
        $this->client->request('POST', $url, $input);
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorExists('input[name="refuelings[0][quantity]"][value="12.5"]');
        self::assertSelectorExists('input[name="trips[1][distance]"][value="200"]');
        $input['_token'] = $token;
        foreach (['314', '316'] as $incorrect) {
            $input['odometerEnd'] = $incorrect;
            $this->client->request('POST', $url, $input);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorExists('input[name="odometerEnd"][value="'.$incorrect.'"]');
            self::assertSelectorExists('input[name="trips[0][enterprise]"][value="Склад"]');
            self::assertSelectorExists('input[name="trips[1][distance]"][value="200"]');
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_route_log'));
            self::assertSame(1, (int) $this->connection->fetchOne('SELECT next_waybill_number FROM pg_temp.waybills_driver_profile'));
        }
        $input['odometerEnd'] = '';
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Склад');
        self::assertSame(315, (int) $this->connection->fetchOne('SELECT odometer_end FROM pg_temp.waybills_waybill'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_route_log'));
        self::assertSame('30.00000000000000000001', $this->connection->fetchOne('SELECT fuel_start::text FROM pg_temp.waybills_waybill'));
        self::assertSame('30.0', $this->connection->fetchOne("SELECT fuel->>'remaining' FROM pg_temp.waybills_waybill"));
    }

    public function test_final_fuel_remaining_is_required_on_create_and_edit_but_zero_is_accepted(): void
    {
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['fuelRemaining'] = '';

        $this->client->request('POST', $url, $input);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-alert', 'Укажите остаток топлива на конец периода.');
        self::assertSelectorExists('input[name="from"][value="2026-01-01"]');
        self::assertSelectorExists('input[name="fuelRemaining"][value=""]');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));

        $input['fuelRemaining'] = '0';
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects();
        $showUrl = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($showUrl);
        self::assertSame('0.0', $this->connection->fetchOne("SELECT fuel->>'remaining' FROM pg_temp.waybills_waybill"));

        $crawler = $this->client->request('GET', $showUrl.'/edit');
        $edit = [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'version' => $crawler->filter('input[name="version"]')->attr('value'),
            'from' => '2026-01-01', 'to' => '2026-01-31', 'odometerEnd' => '100', 'fuelRemaining' => '',
            'tripsSubmitted' => '1', 'refuelingsSubmitted' => '1', 'absencesSubmitted' => '1',
            'trips' => [], 'refuelings' => [], 'absences' => [],
        ];
        $this->client->request('POST', $showUrl.'/edit', $edit);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-alert', 'Укажите остаток топлива на конец периода.');
        self::assertSelectorExists('input[name="fuelRemaining"][value=""]');
        self::assertSame('0.0', $this->connection->fetchOne("SELECT fuel->>'remaining' FROM pg_temp.waybills_waybill"));

        $edit['fuelRemaining'] = '0';
        $this->client->request('POST', $showUrl.'/edit', $edit);
        self::assertResponseRedirects($showUrl);
        self::assertSame('0.0', $this->connection->fetchOne("SELECT fuel->>'remaining' FROM pg_temp.waybills_waybill"));
    }

    public function test_period_dates_use_compact_fields_on_create_and_edit(): void
    {
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        $assertPeriod = static function (Crawler $crawler): void {
            self::assertSelectorTextContains('#sec-period label[for="from"]', 'Начало');
            self::assertSelectorTextContains('#sec-period label[for="to"]', 'Окончание');
            self::assertCount(2, $crawler->filter('#sec-period .row input[type="date"]'));
            self::assertCount(0, $crawler->filter('#sec-period .input-group'));
            self::assertSelectorExists('#sec-period input#from[name="from"]');
            self::assertSelectorExists('#sec-period input#to[name="to"]');
        };
        $assertPeriod($crawler);

        $this->client->request('POST', $url, $this->creationInput($crawler));
        $showUrl = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($showUrl);
        $crawler = $this->client->request('GET', $showUrl.'/edit');
        $assertPeriod($crawler);
    }

    public function test_dynamic_rows_use_shared_action_buttons(): void
    {
        $this->client->request('GET', '/cabinet/waybill/sheets/new/'.$this->vehicleId);

        self::assertSelectorExists('template[data-waybill-form-target="tripTemplate"] input[name="trips[__i__][country]"][value="Россия"]');
        self::assertSelectorExists('template[data-waybill-form-target="tripTemplate"] input[name="trips[__i__][city]"][data-action="input->waybill-form#refreshTripLocations"]');
        self::assertSelectorExists('template[data-waybill-form-target="tripTemplate"] [data-role="trip-insertion"][hidden] button[data-action="click->waybill-form#insert"]');
        foreach (['trip' => 'поездку', 'refueling' => 'заправку', 'absence' => 'период'] as $kind => $label) {
            self::assertSelectorExists(sprintf('template[data-waybill-form-target="%sTemplate"] button.btn.btn-sm.btn-outline-danger[data-action="click->waybill-form#remove"][title="Удалить %s"][aria-label="Удалить %s"] i.bi.bi-trash', $kind, $label, $label));
            self::assertSelectorExists(sprintf('button.btn.btn-soft-success.btn-sm[data-action="click->waybill-form#add"][data-waybill-form-kind-param="%s"] i.bi.bi-plus-lg.me-1', $kind));
        }
    }

    public function test_several_refuelings_are_saved_edited_and_summed(): void
    {
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        self::assertSelectorNotExists('select[name="fuelType"]');
        self::assertSelectorExists('template[data-waybill-form-target="refuelingTemplate"] select[name="refuelings[__i__][fuelType]"] option[value="АИ-95"][selected]');
        self::assertSelectorExists('button[type="button"][data-waybill-form-kind-param="refueling"]');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));

        $input['refuelings'] = [
            ['id' => '', 'date' => '2026-01-10T12:00', 'fuelType' => 'АИ-95', 'quantity' => '0.1'],
            ['id' => '', 'date' => '2026-01-11T12:00', 'fuelType' => 'АИ-92', 'quantity' => '0.20000000000000000001'],
        ];
        $input['trips'] = [
            ['id' => '', 'date' => '2026-01-10T12:00', 'distance' => '1', 'country' => 'Россия', 'city' => 'Первая', 'street' => '', 'house' => '', 'enterprise' => ''],
            ['id' => '', 'date' => '2026-01-11T12:00', 'distance' => '1', 'country' => 'Россия', 'city' => 'Вторая', 'street' => '', 'house' => '', 'enterprise' => ''],
        ];
        $input['odometerEnd'] = '';
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects();
        $showUrl = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($showUrl);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', '0.30000000000000000001');
        self::assertSelectorTextContains('.kv-grid', 'АИ-95, АИ-92');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_fuel_operation'));

        $editUrl = $showUrl.'/edit';
        $crawler = $this->client->request('GET', $editUrl);
        $input['_token'] = $crawler->filter('input[name="_token"]')->attr('value');
        $input['version'] = $crawler->filter('input[name="version"]')->attr('value');
        foreach ([0, 1] as $index) {
            $input['refuelings'][$index]['id'] = $crawler->filter('input[name="refuelings['.$index.'][id]"]')->attr('value');
        }
        unset($input['initialOdometer'], $input['initialFuel']);
        $missing = $input;
        unset($missing['refuelingsSubmitted']);
        $this->client->request('POST', $editUrl, $missing);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-alert', 'Список заправок не был передан');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_fuel_operation'));
        $input['refuelings'][1]['quantity'] = '0.3';
        $this->client->request('POST', $editUrl, $input);
        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '0.4');
        self::assertSame($input['refuelings'][1]['id'], $this->connection->fetchOne('SELECT id FROM pg_temp.waybills_fuel_operation WHERE position = 1'));
    }

    public function test_create_form_can_refresh_changed_initial_readings_without_losing_inputs(): void
    {
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['odometerEnd'] = '115';
        $input['refuelings'] = [['id' => '', 'date' => '2026-01-10T12:00', 'fuelType' => 'АИ-95', 'quantity' => '2']];
        $input['trips'] = [['id' => '', 'date' => '2026-01-10T12:00', 'distance' => '15', 'country' => 'Россия', 'city' => 'Клиент']];
        $this->connection->executeStatement('UPDATE pg_temp.waybills_vehicle SET current_odometer = 110, fuel_remaining = 31 WHERE id = ?', [$this->vehicleId]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->request('POST', $url, $input);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-alert', 'Показания автомобиля изменились');
        self::assertSelectorExists('input[name="odometerEnd"][value="115"]');
        self::assertSelectorExists('input[name="refuelings[0][quantity]"][value="2"]');
        self::assertSelectorExists('input[name="trips[0][city]"][value="Клиент"]');
        self::assertSelectorExists('button[name="action"][value="refresh_readings"]');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));
        $input['action'] = 'refresh_readings';
        $crawler = $this->client->request('POST', $url, $input);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="initialOdometer"][value="110"]');
        self::assertSelectorExists('input[name="initialFuel"][value="31"]');
        self::assertSelectorExists('input[name="odometerEnd"][value="115"]');
        self::assertSelectorExists('input[name="trips[0][city]"][value="Клиент"]');
        $input['initialOdometer'] = $crawler->filter('input[name="initialOdometer"]')->attr('value');
        $input['initialFuel'] = $crawler->filter('input[name="initialFuel"]')->attr('value');
        $input['odometerEnd'] = '';
        unset($input['action']);
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects();
        self::assertSame(125, (int) $this->connection->fetchOne('SELECT odometer_end FROM pg_temp.waybills_waybill'));
        self::assertSame('31', $this->connection->fetchOne('SELECT fuel_start::text FROM pg_temp.waybills_waybill'));
    }

    public function test_creation_rechecks_current_driver_after_the_form_was_opened(): void
    {
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['refuelings'] = [['id' => '', 'date' => '2026-01-10T12:00', 'fuelType' => 'АИ-95', 'quantity' => '3']];
        $this->connection->executeStatement('UPDATE pg_temp.waybills_vehicle SET driver_id = NULL WHERE id = ?', [$this->vehicleId]);
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $this->client->request('POST', $url, $input);
        self::assertResponseStatusCodeSame(403);
        self::assertSelectorExists('input[name="refuelings[0][quantity]"][value="3"]');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT next_waybill_number FROM pg_temp.waybills_driver_profile'));
    }

    public function test_odometer_is_validated_and_can_be_calculated_without_javascript(): void
    {
        $createUrl = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $createUrl);
        $this->client->request('POST', $createUrl, $this->creationInput($crawler));
        self::assertResponseRedirects();
        $showUrl = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($showUrl);
        $editUrl = $showUrl.'/edit';
        $crawler = $this->client->request('GET', $editUrl);
        self::assertSelectorExists('input[name="odometerEnd"][value="100"]');
        $input = [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'version' => '1', 'from' => '2026-01-01', 'to' => '2026-01-31',
            'fuelRemaining' => '30', 'tripsSubmitted' => '1', 'refuelingsSubmitted' => '1', 'absencesSubmitted' => '1',
            'trips' => [
                ['id' => '', 'date' => '2026-01-10T12:00', 'distance' => '15', 'country' => 'Россия', 'city' => 'Первая', 'enterprise' => 'Клиент'],
                ['id' => '', 'date' => '2026-01-11T12:00', 'distance' => '200', 'country' => 'Россия', 'city' => 'Вторая'],
            ],
        ];

        foreach (['314', '316'] as $incorrect) {
            $input['odometerEnd'] = $incorrect;
            $this->client->request('POST', $editUrl, $input);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorExists('input[name="odometerEnd"][value="'.$incorrect.'"]');
            self::assertSelectorExists('input[name="trips[0][enterprise]"][value="Клиент"]');
            self::assertSelectorExists('input[name="trips[1][distance]"][value="200"]');
            self::assertSame(100, (int) $this->connection->fetchOne('SELECT odometer_end FROM pg_temp.waybills_waybill'));
            self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_route_log'));
        }

        $input['odometerEnd'] = '';
        $this->client->request('POST', $editUrl, $input);
        self::assertResponseRedirects();
        self::assertSame(315, (int) $this->connection->fetchOne('SELECT odometer_end FROM pg_temp.waybills_waybill'));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_route_log'));

        $crawler = $this->client->request('GET', $editUrl);
        $input['version'] = $crawler->filter('input[name="version"]')->attr('value');
        $input['trips'][0]['id'] = $crawler->filter('input[name="trips[0][id]"]')->attr('value');
        $input['trips'][1]['id'] = $crawler->filter('input[name="trips[1][id]"]')->attr('value');
        unset($input['trips'][1]);
        $this->client->request('POST', $editUrl, $input);
        self::assertResponseRedirects();
        self::assertSame(115, (int) $this->connection->fetchOne('SELECT odometer_end FROM pg_temp.waybills_waybill'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_route_log'));
    }

    public function test_trips_can_be_removed_and_inserted_in_the_middle(): void
    {
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['odometerEnd'] = '';
        $input['trips'] = [
            ['id' => '', 'date' => '2026-01-10T12:00', 'distance' => '10', 'country' => 'Россия', 'city' => 'Первая', 'street' => '', 'house' => '', 'enterprise' => ''],
            ['id' => '', 'date' => '2026-01-11T12:00', 'distance' => '20', 'country' => 'Россия', 'city' => 'Удаляемая', 'street' => '', 'house' => '', 'enterprise' => ''],
            ['id' => '', 'date' => '2026-01-12T12:00', 'distance' => '30', 'country' => 'Россия', 'city' => 'Последняя', 'street' => '', 'house' => '', 'enterprise' => ''],
        ];
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects();
        $showUrl = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($showUrl);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Россия, База → Россия, Первая');

        $crawler = $this->client->request('GET', $showUrl.'/edit');
        $input['_token'] = $crawler->filter('input[name="_token"]')->attr('value');
        $input['version'] = $crawler->filter('input[name="version"]')->attr('value');
        foreach ([0, 1, 2] as $index) {
            $input['trips'][$index]['id'] = $crawler->filter('input[name="trips['.$index.'][id]"]')->attr('value');
        }
        unset($input['initialOdometer'], $input['initialFuel'], $input['trips'][1]);
        $input['trips'] = array_values($input['trips']);
        $this->client->request('POST', $showUrl.'/edit', $input);

        self::assertResponseRedirects();
        $trips = $this->connection->fetchAllAssociative('SELECT id, previous_id, location FROM pg_temp.waybills_route_log ORDER BY occurred_at');
        self::assertCount(2, $trips);
        self::assertNull($trips[0]['previous_id']);
        self::assertSame($trips[0]['id'], $trips[1]['previous_id']);
        self::assertSame('Первая', json_decode($trips[0]['location'], true)['city']['name']);
        self::assertSame('Последняя', json_decode($trips[1]['location'], true)['city']['name']);
        self::assertSame(140, (int) $this->connection->fetchOne('SELECT odometer_end FROM pg_temp.waybills_waybill'));

        $crawler = $this->client->request('GET', $showUrl.'/edit');
        $input['_token'] = $crawler->filter('input[name="_token"]')->attr('value');
        $input['version'] = $crawler->filter('input[name="version"]')->attr('value');
        foreach ([0, 1] as $index) {
            $input['trips'][$index]['id'] = $crawler->filter('input[name="trips['.$index.'][id]"]')->attr('value');
        }
        $inserted = ['id' => '', 'date' => '2026-01-11T12:00', 'distance' => '20', 'country' => 'Россия', 'city' => 'Вставленная', 'street' => '', 'house' => '', 'enterprise' => ''];
        $input['trips'] = [$input['trips'][0], $inserted, $input['trips'][1]];
        $this->client->request('POST', $showUrl.'/edit', $input);

        self::assertResponseRedirects();
        $trips = $this->connection->fetchAllAssociative('SELECT id, previous_id, location FROM pg_temp.waybills_route_log ORDER BY occurred_at');
        self::assertCount(3, $trips);
        self::assertNull($trips[0]['previous_id']);
        self::assertSame($trips[0]['id'], $trips[1]['previous_id']);
        self::assertSame($trips[1]['id'], $trips[2]['previous_id']);
        self::assertSame('Вставленная', json_decode($trips[1]['location'], true)['city']['name']);
        self::assertSame('Последняя', json_decode($trips[2]['location'], true)['city']['name']);
        self::assertSame(160, (int) $this->connection->fetchOne('SELECT odometer_end FROM pg_temp.waybills_waybill'));
    }

    public function test_download_and_approval_work_in_browser_flow(): void
    {
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['fuelRemaining'] = '20';
        $input['odometerEnd'] = '';
        $input['trips'] = [['id' => '', 'date' => '2026-01-10T12:00', 'distance' => '5', 'country' => 'Россия', 'city' => 'Москва', 'street' => '', 'house' => '', 'enterprise' => 'Склад']];
        $input['refuelings'] = [['id' => '', 'date' => '2026-01-10T12:00', 'fuelType' => 'АИ-95', 'quantity' => '12.5']];
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects();
        $showUrl = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($showUrl);

        $this->client->request('GET', $showUrl.'/download');
        self::assertResponseIsSuccessful();
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $text = $this->xlsxText((string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('Водитель', $text);
        self::assertStringContainsString('A123BC77', $text);
        self::assertStringContainsString('Склад', $text);
        self::assertStringContainsString('12.5', $text);
        self::assertStringContainsString('22.50000000000000000001', $text);
        self::assertStringNotContainsString('{{', $text);

        $crawler = $this->client->request('GET', $showUrl);
        $approve = [
            '_token' => $crawler->filter('form[action$="/approve"] input[name="_token"]')->attr('value'),
            'version' => $crawler->filter('form[action$="/approve"] input[name="version"]')->attr('value'),
        ];
        $this->client->request('POST', $showUrl.'/approve', $approve);
        self::assertResponseRedirects($showUrl);
        $crawler = $this->client->followRedirect();
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT locked FROM pg_temp.waybills_waybill'));
        self::assertSame(105, (int) $this->connection->fetchOne('SELECT current_odometer FROM pg_temp.waybills_vehicle'));
        self::assertSame('20', $this->connection->fetchOne('SELECT fuel_remaining::text FROM pg_temp.waybills_vehicle'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_fuel_operation WHERE type = 'reconciliation'"));
        self::assertCount(0, $crawler->filter('a[href$="/edit"]'));
        $this->client->request('GET', $showUrl.'/download');
        self::assertResponseIsSuccessful();
        $this->client->request('POST', $showUrl.'/approve', $approve);
        self::assertResponseRedirects($showUrl);
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_fuel_operation WHERE type = 'reconciliation'"));

        $other = new User(new Email('other-waybill-download@example.com'));
        (new \ReflectionProperty($other, 'roles'))->setValue($other, ['ROLE_USER']);
        (new \ReflectionProperty($other, 'isActive'))->setValue($other, true);
        $this->connection->executeStatement("INSERT INTO pg_temp.user_user (ulid, email, password, roles, is_active) VALUES (?, ?, 'test', '[\"ROLE_USER\"]', true)", [$other->getUlid(), 'other-waybill-download@example.com']);
        $this->client->loginUser($other);
        $this->client->request('GET', $showUrl.'/download');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_absences_and_draft_deletion(): void
    {
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        self::assertSelectorExists('template[data-waybill-form-target="absenceTemplate"] input[type="date"][name="absences[__i__][from]"]');
        self::assertSelectorExists('template[data-waybill-form-target="absenceTemplate"] input[type="date"][name="absences[__i__][to]"]');
        self::assertSelectorExists('#sec-absences button[type="button"][data-waybill-form-kind-param="absence"]');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));

        $input['absences'] = [
            ['from' => '2026-01-22', 'to' => '2026-01-23'],
        ];
        $input['refuelings'] = [['id' => '', 'date' => '2026-01-23T23:30', 'fuelType' => 'АИ-95', 'quantity' => '5']];
        $this->client->request('POST', $url, $input);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-alert', 'Заправка попадает в период без поездок');
        $input['refuelings'] = [];
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects();
        $showUrl = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($showUrl);
        $crawler = $this->client->request('GET', $showUrl);
        self::assertSelectorTextContains('body', '22.01.2026 00:00:00');
        $delete = [
            '_token' => $crawler->filter('form[action$="/delete"] input[name="_token"]')->attr('value'),
            'version' => $crawler->filter('form[action$="/delete"] input[name="version"]')->attr('value'),
        ];

        $crawler = $this->client->request('GET', $showUrl.'/edit');
        self::assertSelectorExists('input[type="date"][name="absences[0][from]"][value="2026-01-22"]');
        self::assertSelectorExists('input[type="date"][name="absences[0][to]"][value="2026-01-23"]');
        $save = [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'version' => $crawler->filter('input[name="version"]')->attr('value'),
            'from' => '2026-01-01', 'to' => '2026-01-31', 'odometerEnd' => '100',
            'fuelRemaining' => '30',
            'tripsSubmitted' => '1', 'refuelingsSubmitted' => '1', 'absencesSubmitted' => '1',
            'trips' => [], 'refuelings' => [], 'absences' => $input['absences'],
        ];
        $this->client->request('POST', $showUrl.'/edit', $save);
        self::assertResponseRedirects();
        $this->client->request('POST', $showUrl.'/delete', $delete);
        self::assertResponseRedirects($showUrl);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));

        $crawler = $this->client->request('GET', $showUrl);
        $delete['version'] = $crawler->filter('form[action$="/delete"] input[name="version"]')->attr('value');
        $delete['_token'] = $crawler->filter('form[action$="/delete"] input[name="_token"]')->attr('value');
        $this->client->request('POST', $showUrl.'/delete', $delete);
        self::assertResponseRedirects('/cabinet/waybill/sheets');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_route_log'));

        $crawler = $this->client->request('GET', $url);
        $this->client->request('POST', $url, $this->creationInput($crawler));
        self::assertResponseRedirects();
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT number FROM pg_temp.waybills_waybill'));
    }

    public function test_empty_route_and_zero_refuelings_can_be_approved(): void
    {
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        $input = $this->creationInput($crawler);
        $input['fuelRemaining'] = '30';
        $input['fuelType'] = 'АИ-98';
        $this->client->request('POST', $url, $input);
        self::assertResponseRedirects();
        $showUrl = $this->client->getResponse()->headers->get('Location');
        self::assertNotNull($showUrl);
        self::assertSame('АИ-95', $this->connection->fetchOne("SELECT fuel->>'type' FROM pg_temp.waybills_waybill"));

        $crawler = $this->client->request('GET', $showUrl);
        $approve = [
            '_token' => $crawler->filter('form[action$="/approve"] input[name="_token"]')->attr('value'),
            'version' => $crawler->filter('form[action$="/approve"] input[name="version"]')->attr('value'),
        ];
        $this->client->request('POST', $showUrl.'/approve', $approve);

        self::assertResponseRedirects($showUrl);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT locked FROM pg_temp.waybills_waybill'));
        self::assertSame(100, (int) $this->connection->fetchOne('SELECT current_odometer FROM pg_temp.waybills_vehicle'));
        self::assertSame('30', $this->connection->fetchOne('SELECT fuel_remaining::text FROM pg_temp.waybills_vehicle'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_route_log'));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM pg_temp.waybills_fuel_operation WHERE type = 'reconciliation'"));
    }

    /** @return array{items: list<array<string, mixed>>, page: int, hasMore: bool} */
    private function locationSuggestions(string $query, int $page = 1): array
    {
        $this->client->request('GET', '/cabinet/waybill/locations/suggest?'.http_build_query(['q' => $query, 'page' => $page]));
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        $response = json_decode($this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return $response['data'] ?? $response;
    }

    private function assertCompactWaybillForm(Crawler $crawler): void
    {
        self::assertSelectorTextContains('#waybill-form .kvi .v', 'Водитель');
        self::assertSelectorTextContains('#waybill-form', 'Модель — A123BC77');
        self::assertSame(['sec-period', 'sec-refuel', 'sec-absences', 'sec-trips'], $crawler->filter('#waybill-form > [data-form-scrollspy-target="section"]')->each(static fn (Crawler $section): ?string => $section->attr('id')));
        self::assertSelectorExists('#sec-period input[name="from"]');
        self::assertSelectorExists('#sec-period input[name="to"]');
        self::assertSelectorExists('#sec-trips input[name="odometerEnd"]');
        self::assertSelectorExists('#sec-refuel input[name="fuelRemaining"]');
        self::assertSelectorExists('#sec-refuel button.w-100[data-waybill-form-kind-param="refueling"]');
        self::assertSelectorExists('#sec-absences button.w-100[data-waybill-form-kind-param="absence"]');
        self::assertSelectorExists('#sec-trips button.w-100[data-waybill-form-kind-param="trip"]');
        self::assertSelectorExists('template[data-waybill-form-target="tripTemplate"] details[open]');
        self::assertSelectorExists('template[data-waybill-form-target="refuelingTemplate"]');
        self::assertSelectorNotExists('.form-appbar button[form="waybill-form"]');
        self::assertSelectorExists('.form-savebar button[form="waybill-form"]');
    }

    /** @return list<array<string, string>> */
    private function postedTrips(Crawler $crawler): array
    {
        $fields = ['id', 'date', 'distance', 'locationId', 'country', 'city', 'cityType', 'street', 'house', 'enterprise', 'origin', 'pairId', 'originalDate', 'originalDistance', 'originalLocationId', 'originalCountry', 'originalCity', 'originalCityType', 'originalStreet', 'originalHouse', 'originalEnterprise', 'originalLatitude', 'originalLongitude'];

        return $crawler->filter('#trip-rows [data-waybill-form-target~="tripRow"]')->each(static function (Crawler $row) use ($fields): array {
            $trip = [];
            foreach ($fields as $field) {
                $node = $row->filter(sprintf('input[name$="[%s]"], select[name$="[%s]"]', $field, $field));
                if ('select' === $node->nodeName()) {
                    $selected = $node->filter('option[selected]');
                    $trip[$field] = $selected->count() ? $selected->attr('value') : '';
                } else {
                    $trip[$field] = $node->attr('value') ?? '';
                }
            }

            return $trip;
        });
    }

    /** @return array<string, mixed> */
    private function creationInput(Crawler $crawler): array
    {
        return [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'from' => '2026-01-01', 'to' => '2026-01-31',
            'initialOdometer' => $crawler->filter('input[name="initialOdometer"]')->attr('value'),
            'initialFuel' => $crawler->filter('input[name="initialFuel"]')->attr('value'),
            'odometerEnd' => $crawler->filter('input[name="odometerEnd"]')->attr('value'),
            'fuelRemaining' => '30', 'tripsSubmitted' => '1', 'refuelingsSubmitted' => '1', 'absencesSubmitted' => '1',
            'trips' => [], 'absences' => [],
        ];
    }

    private function xlsxText(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'waybill_test_');
        self::assertNotFalse($path);
        try {
            self::assertSame(strlen($content), file_put_contents($path, $content));
            $spreadsheet = IOFactory::load($path);
            $text = [];
            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->getCellIterator();
                    $cells->setIterateOnlyExistingCells(true);
                    foreach ($cells as $cell) {
                        $text[] = (string) $cell->getValue();
                    }
                }
            }
            $spreadsheet->disconnectWorksheets();

            return implode("\n", $text);
        } finally {
            unlink($path);
        }
    }

    /** @param class-string<AbstractMigration> $migrationClass */
    private function applyTemporaryMigration(string $migrationClass): void
    {
        $migration = new $migrationClass($this->connection, new NullLogger());
        $migration->up(new Schema());
        $this->connection->beginTransaction();
        try {
            foreach ($migration->getSql() as $query) {
                $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $query->getStatement());
                $this->connection->executeStatement($sql, $query->getParameters(), $query->getTypes());
            }
            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }
    }
}
