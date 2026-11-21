<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Infrastructure\Controller;

use App\Shared\Domain\Audit\TrackedClassRepositoryInterface;
use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Audit\CachedAuditPolicy;
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
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DomCrawler\Crawler;
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
        foreach ([Version20261121000000::class, Version20261121000001::class, Version20261121000002::class, Version20261121000003::class, Version20261121000004::class, Version20261121000005::class, Version20261121000006::class, Version20261121000007::class, Version20261121000008::class, Version20261121000009::class, Version20261121000010::class] as $migrationClass) {
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
        $token = $crawler->filter('input[name="_token"]')->attr('value');
        $input = [
            '_token' => $token, 'version' => '1', 'from' => '2026-01-01', 'to' => '2026-01-31',
            'odometerEnd' => '', 'fuelRemaining' => '', 'tripsSubmitted' => '1', 'refuelingsSubmitted' => '1', 'absencesSubmitted' => '1',
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
        self::assertNull($this->connection->fetchOne("SELECT fuel->>'remaining' FROM pg_temp.waybills_waybill"));

        $input['refuelings'][0]['quantity'] = '88';
        $this->client->request('POST', $editUrl, $input);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-alert', 'изменился');
        self::assertSelectorExists('input[name="refuelings[0][quantity]"][value="88"]');
        self::assertEquals('12.5', $this->connection->fetchOne('SELECT amount::text FROM pg_temp.waybills_fuel_operation'));

        $crawler = $this->client->request('GET', $editUrl);
        self::assertResponseIsSuccessful();
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

    public function test_another_administrator_cannot_create_a_draft_for_the_current_driver(): void
    {
        $other = new User(new Email('other-waybill-web@example.com'));
        (new \ReflectionProperty($other, 'roles'))->setValue($other, ['ROLE_ADMIN']);
        (new \ReflectionProperty($other, 'isActive'))->setValue($other, true);
        $this->connection->executeStatement("INSERT INTO pg_temp.user_user (ulid, email, password, roles, is_active) VALUES (?, ?, 'test', '[\"ROLE_ADMIN\"]', true)", [$other->getUlid(), 'other-waybill-web@example.com']);
        $this->client->loginUser($other);
        $url = '/cabinet/waybill/sheets/new/'.$this->vehicleId;
        $crawler = $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
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
        self::assertSelectorExists('#sec-period input[name="from"]');
        self::assertSelectorExists('#sec-trips button[type="button"][data-waybill-form-kind-param="trip"]');
        self::assertSelectorExists('template[data-waybill-form-target="tripTemplate"]');
        self::assertSelectorExists('#sec-fuel input[name="odometerEnd"][value="100"]');
        self::assertSelectorExists('#sec-fuel button[type="button"][data-waybill-form-kind-param="refueling"]');
        self::assertSelectorExists('template[data-waybill-form-target="refuelingTemplate"]');
        self::assertSelectorNotExists('#sec-fuel input[name="refueled"]');
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
        self::assertNull($this->connection->fetchOne("SELECT fuel->>'remaining' FROM pg_temp.waybills_waybill"));
    }

    public function test_period_dates_share_one_input_group(): void
    {
        $crawler = $this->client->request('GET', '/cabinet/waybill/sheets/new/'.$this->vehicleId);

        self::assertSelectorTextContains('#sec-period label[for="from"]', 'Период');
        self::assertCount(2, $crawler->filter('#sec-period .input-group input[type="date"]'));
        self::assertSelectorExists('#sec-period .input-group input#from[name="from"][aria-label="Начало периода"] + span.input-group-text + input#to[name="to"][aria-label="Конец периода"]');
    }

    public function test_dynamic_rows_use_shared_action_buttons(): void
    {
        $this->client->request('GET', '/cabinet/waybill/sheets/new/'.$this->vehicleId);

        self::assertSelectorExists('template[data-waybill-form-target="tripTemplate"] input[name="trips[__i__][country]"][value="Россия"]');
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
            'fuelRemaining' => '', 'tripsSubmitted' => '1', 'refuelingsSubmitted' => '1', 'absencesSubmitted' => '1',
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
        self::assertSame('Первая', json_decode($trips[0]['location'], true)['city']);
        self::assertSame('Последняя', json_decode($trips[1]['location'], true)['city']);
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
        self::assertSame('Вставленная', json_decode($trips[1]['location'], true)['city']);
        self::assertSame('Последняя', json_decode($trips[2]['location'], true)['city']);
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
        self::assertSelectorExists('template[data-waybill-form-target="absenceTemplate"] input[name="absences[__i__][from]"]');
        self::assertSelectorExists('#sec-absences button[type="button"][data-waybill-form-kind-param="absence"]');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pg_temp.waybills_waybill'));

        $input['absences'] = [
            ['from' => '2026-01-22T00:00', 'to' => '2026-01-23T23:59'],
        ];
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
        $save = [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'version' => $crawler->filter('input[name="version"]')->attr('value'),
            'from' => '2026-01-01', 'to' => '2026-01-31', 'odometerEnd' => '100',
            'fuelRemaining' => '',
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

    /** @return array<string, mixed> */
    private function creationInput(Crawler $crawler): array
    {
        return [
            '_token' => $crawler->filter('input[name="_token"]')->attr('value'),
            'from' => '2026-01-01', 'to' => '2026-01-31',
            'initialOdometer' => $crawler->filter('input[name="initialOdometer"]')->attr('value'),
            'initialFuel' => $crawler->filter('input[name="initialFuel"]')->attr('value'),
            'odometerEnd' => $crawler->filter('input[name="odometerEnd"]')->attr('value'),
            'fuelRemaining' => '', 'tripsSubmitted' => '1', 'refuelingsSubmitted' => '1', 'absencesSubmitted' => '1',
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
