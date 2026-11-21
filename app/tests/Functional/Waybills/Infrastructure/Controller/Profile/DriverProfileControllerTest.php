<?php

declare(strict_types=1);

namespace App\Tests\Functional\Waybills\Infrastructure\Controller\Profile;

use App\Shared\Infrastructure\Database\Migrations\Version20261121000000;
use App\Shared\Infrastructure\Database\Migrations\Version20261121000002;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Entity\ValueObject\Email;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DriverProfileControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        self::assertSame('test_db', $em->getConnection()->getParams()['host'] ?? null);
        self::assertSame('app_test', $em->getConnection()->getParams()['dbname'] ?? null);
        $connection = $em->getConnection();
        $connection->executeStatement('CREATE TEMPORARY TABLE user_user (ulid VARCHAR(26) PRIMARY KEY, email VARCHAR(255) NOT NULL UNIQUE, password VARCHAR(255), roles JSON NOT NULL, is_active BOOLEAN NOT NULL)');
        $connection->executeStatement('CREATE TEMPORARY TABLE user_channel (id UUID PRIMARY KEY, owner_id VARCHAR(26) NOT NULL REFERENCES user_user(ulid), type VARCHAR(100) NOT NULL, value TEXT NOT NULL, is_verified BOOLEAN NOT NULL, verified_at TIMESTAMP(0) WITHOUT TIME ZONE)');
        $connection->executeStatement('CREATE TEMPORARY TABLE notification (id UUID PRIMARY KEY, owner_id VARCHAR(26) NOT NULL, is_read BOOLEAN NOT NULL)');
        foreach ([Version20261121000000::class, Version20261121000002::class] as $migrationClass) {
            $migration = new $migrationClass($connection, new NullLogger());
            $migration->up(new Schema());
            foreach ($migration->getSql() as $query) {
                $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $query->getStatement());
                $connection->executeQuery($sql, $query->getParameters(), $query->getTypes());
            }
        }
        $user = new User(new Email('driver-'.bin2hex(random_bytes(8)).'@example.com'));
        (new \ReflectionProperty($user, 'isActive'))->setValue($user, true);
        (new \ReflectionProperty($user, 'roles'))->setValue($user, ['ROLE_ADMIN']);
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/cabinet/waybills/profile/edit');
        self::assertResponseIsSuccessful();
        $this->token = (string) $crawler->filter('input[name="_token"]')->attr('value');
    }

    public function test_auto_section_is_available_in_shared_menu(): void
    {
        self::assertSelectorTextContains('#mainMenu', 'Авто');
        self::assertSelectorExists('#mainMenu a[href="/cabinet/waybill/sheets"] .bi-file-earmark-spreadsheet');
        self::assertSelectorExists('#mainMenu a.active[href="/cabinet/waybills/profile"] .bi-file-earmark-person');
        self::assertSelectorExists('#mainMenu a[href="/cabinet/waybill/vehicle"] .bi-car-front');
        self::assertSelectorExists('.app-sidebar a[href="/cabinet/waybill/sheets"] .bi-file-earmark-spreadsheet');
        self::assertSelectorExists('.app-sidebar a.active[aria-current="page"][href="/cabinet/waybills/profile"] .bi-file-earmark-person');
        self::assertSelectorExists('.app-sidebar a[href="/cabinet/waybill/vehicle"] .bi-car-front');
        self::assertSelectorExists('.app-tabbar button[data-bs-target="#mainMenu"]');

        $this->client->request('GET', '/cabinet/waybill/vehicle');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#mainMenu a.active[href="/cabinet/waybill/vehicle"]');
        self::assertSelectorNotExists('#mainMenu a.active[href="/cabinet/waybills/profile"]');
        self::assertSelectorExists('.app-sidebar a.active[aria-current="page"][href="/cabinet/waybill/vehicle"]');
        self::assertSelectorNotExists('.app-sidebar a.active[href="/cabinet/waybills/profile"]');
    }

    public function test_non_admin_cannot_access_auto_routes_or_see_menu(): void
    {
        $user = new User(new Email('regular-'.bin2hex(random_bytes(8)).'@example.com'));
        (new \ReflectionProperty($user, 'isActive'))->setValue($user, true);
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/cabinet/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('#mainMenu a[href="/cabinet/waybills/profile"]');
        self::assertSelectorNotExists('#mainMenu a[href="/cabinet/waybill/vehicle"]');
        self::assertSelectorNotExists('.app-sidebar a[href="/cabinet/waybills/profile"]');
        self::assertSelectorNotExists('.app-sidebar a[href="/cabinet/waybill/vehicle"]');
        self::assertSelectorTextNotContains('#mainMenu', 'Путевые листы');
        self::assertSelectorTextNotContains('.app-sidebar', 'Путевые листы');

        foreach (['/cabinet/waybills/profile', '/cabinet/waybills/profile/edit', '/cabinet/waybill/vehicle', '/cabinet/waybill/vehicle/new', '/cabinet/waybill/vehicle/suggest'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403);
        }
        foreach (['/cabinet/waybills/profile/edit', '/cabinet/waybill/vehicle/new', '/cabinet/waybill/vehicle/00000000-0000-0000-0000-000000000000/enter'] as $url) {
            $this->client->request('POST', $url, ['_token' => $this->token]);
            self::assertResponseStatusCodeSame(403);
        }
    }

    public function test_anonymous_user_is_redirected_to_login_for_auto_routes(): void
    {
        $this->client->request('GET', '/logout');
        $this->client->request('GET', '/cabinet/waybills/profile');
        self::assertResponseRedirects();
        $this->client->request('GET', '/cabinet/waybill/vehicle');
        self::assertResponseRedirects();
    }

    public function test_create_view_edit_and_server_side_rows_without_javascript(): void
    {
        self::assertSelectorExists('form#driver-profile-form > section#driver-details');
        self::assertSelectorExists('form#driver-profile-form > section#driver-route input[name="base[country]"]');
        self::assertSelectorExists('input[name="licenseIssuedAt"][type="date"]');
        self::assertSelectorExists('input[name="licenseNumber"][data-controller="driver-license-mask"]');
        self::assertSelectorExists('input[name="snils"][inputmode="numeric"][data-controller="snils-mask"]');
        self::assertSelectorExists('form#driver-profile-form > section#driver-destinations button[type="submit"][name="action"][value="add"] .bi-plus-lg');
        self::assertSelectorExists('input[name="base[country]"][value="Россия"]');
        $data = $this->data();
        $data['base']['country'] = 'Беларусь';
        $data['destinations'][0]['location']['country'] = '';
        $data['destinations'][0]['distance'] = 'не число';
        $this->post($data + ['action' => 'add']);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="destinations[1][location][city]"]');
        self::assertSelectorExists('input[name="base[country]"][value="Беларусь"]');
        self::assertSelectorExists('input[name="destinations[0][location][country]"][value=""]');
        self::assertSelectorExists('input[name="destinations[1][location][country]"][value="Россия"]');
        self::assertSelectorExists('input[name="destinations[1][distance]"][type="text"][inputmode="numeric"]');
        self::assertSelectorExists('input[name="destinations[1][enterprise]"]');
        self::assertSelectorExists('#driver-destinations button[type="submit"][name="action"][value="remove:1"][aria-label="Удалить направление"] .bi-trash');
        self::assertSelectorExists('input[name="destinations[0][distance]"][value="не число"]');
        $data['destinations'][1] = ['location' => ['city' => 'Вторая точка'], 'distance' => '1', 'enterprise' => ''];
        $this->post($data + ['action' => 'remove:1']);
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('input[name="destinations[1][location][city]"]');
        self::assertSelectorExists('input[name="destinations[0][distance]"][value="не число"]');

        $data = $this->data();
        $data['licenseNumber'] = '77 аа 123456';
        $this->post($data);
        self::assertResponseRedirects('/cabinet/waybills/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Иванов Иван');
        self::assertSelectorTextContains('body', 'Склад');
        self::assertSelectorTextContains('body', 'Предприятие');
        self::assertSelectorTextContains('body', '10');
        self::assertSelectorTextContains('body', '112-233-445 95');
        self::assertSelectorTextContains('body', '77 AA 123456');
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame('11223344595', $connection->fetchOne("SELECT driver_details->>'snils' FROM waybills_driver_profile"));
        self::assertSame('77AA123456', $connection->fetchOne("SELECT driver_details->>'licenseNumber' FROM waybills_driver_profile"));
        self::assertSame('10', $connection->fetchOne("SELECT route_settings->'destinations'->0->>'distance' FROM waybills_driver_profile"));
        self::assertSame('Предприятие', $connection->fetchOne("SELECT route_settings->'destinations'->0->>'enterprise' FROM waybills_driver_profile"));
        $crawler = $this->client->request('GET', '/cabinet/waybills/profile/edit');
        $this->token = (string) $crawler->filter('input[name="_token"]')->attr('value');
        self::assertSelectorNotExists('input[name="vehicleId"]');
        self::assertSelectorExists('input[name="snils"][value="112-233-445 95"]');
        self::assertSelectorExists('input[name="licenseNumber"][value="77 AA 123456"]');
        self::assertSelectorExists('input[name="destinations[0][distance]"][value="10"]');
        self::assertSelectorExists('input[name="destinations[0][enterprise]"][value="Предприятие"]');
        $data['fullName'] = 'Петров Пётр';
        $data['destinations'][0]['location']['street'] = 'Клиент';
        $data['destinations'][0]['enterprise'] = '';
        $this->post($data);
        self::assertResponseRedirects('/cabinet/waybills/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Петров Пётр');
        self::assertSelectorTextContains('body', 'Клиент');
        self::assertSelectorTextNotContains('body', 'Предприятие');
        self::assertSame('null', $connection->fetchOne("SELECT (route_settings->'destinations'->0->'enterprise')::text FROM waybills_driver_profile"));
    }

    public function test_invalid_values_are_preserved_and_domain_errors_shown(): void
    {
        $data = $this->data();
        $data['destinations'][0]['distance'] = 'не число';
        $this->post($data);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'Укажите целое число километров для расстояния.');
        self::assertSelectorExists('input[name="destinations[0][distance]"][value="не число"]');
        self::assertSelectorExists('input[name="destinations[0][enterprise]"][value="  Предприятие  "]');
        self::assertSelectorNotExists('input[name="vehicleId"]');

        $data['destinations'][0]['distance'] = '0';
        $this->post($data);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="destinations[0][distance]"][value="0"]');

        $data['licenseIssuedAt'] = 'ошибка';
        $this->post($data);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('input[name="licenseIssuedAt"][value="ошибка"]');
    }

    public function test_enterprise_named_zero_is_shown(): void
    {
        $data = $this->data();
        $data['destinations'][0]['enterprise'] = '0';
        $this->post($data);
        self::assertResponseRedirects('/cabinet/waybills/profile');
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', '— 0');
    }

    public function test_invalid_snils_is_rejected_and_input_is_preserved(): void
    {
        $data = $this->data();
        $data['snils'] = '112-233-445 96';
        $this->post($data);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'Неверная контрольная сумма СНИЛС.');
        self::assertSelectorExists('input[name="snils"][value="112-233-445 96"]');
        self::assertSelectorExists('input[name="fullName"][value="Иванов Иван"]');

        $data['snils'] = '123';
        $this->post($data);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'СНИЛС из 11 цифр');
        self::assertSelectorExists('input[name="snils"][value="123"]');
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM waybills_driver_profile'));
    }

    public function test_invalid_license_is_rejected_and_input_is_preserved(): void
    {
        $data = $this->data();
        $data['licenseNumber'] = '77 АБ 123456';
        $this->post($data);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'водительского удостоверения');
        self::assertSelectorExists('input[name="licenseNumber"][value="77 АБ 123456"]');
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM waybills_driver_profile'));
    }

    #[DataProvider('invalidDistances')]
    public function test_invalid_distances_are_rejected_by_the_server(string $distance): void
    {
        $data = $this->data();
        $data['destinations'][0]['distance'] = $distance;
        $this->post($data);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('form [role="alert"]');
        self::assertSelectorExists('input[name="destinations[0][distance]"][value="'.$distance.'"]');
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();
        self::assertSame(0, (int) $connection->fetchOne('SELECT count(*) FROM waybills_driver_profile'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidDistances(): iterable
    {
        yield 'text' => ['ывапмвап'];
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'infinite' => ['1e999'];
    }

    public function test_invalid_calendar_date_is_rejected_by_the_server(): void
    {
        $data = $this->data();
        $data['licenseIssuedAt'] = '2026-02-30';
        $this->post($data);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert-danger', 'Укажите корректную дату выдачи удостоверения.');
    }

    public function test_invalid_csrf_does_not_save_or_change_rows(): void
    {
        $this->token = 'invalid';
        $this->post($this->data());
        self::assertResponseStatusCodeSame(403);
        $this->post($this->data() + ['action' => 'add']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/cabinet/waybills/profile');
        self::assertSelectorTextContains('body', 'ещё не создана');
    }

    public function test_other_user_sees_only_their_own_profile(): void
    {
        $this->post($this->data());
        self::assertResponseRedirects('/cabinet/waybills/profile');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $other = new User(new Email('driver-other-'.bin2hex(random_bytes(8)).'@example.com'));
        (new \ReflectionProperty($other, 'isActive'))->setValue($other, true);
        (new \ReflectionProperty($other, 'roles'))->setValue($other, ['ROLE_ADMIN']);
        $em->persist($other);
        $em->flush();
        $this->client->loginUser($other);
        $this->client->request('GET', '/cabinet/waybills/profile');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'ещё не создана');
        self::assertSelectorTextNotContains('body', 'Иванов Иван');
    }

    /** @return array{fullName: string, licenseNumber: string, licenseIssuedAt: string, snils: string, base: array<string, string>, destinations: list<array{location: array<string, string>, distance: string, enterprise: string}>} */
    private function data(): array
    {
        return [
            'fullName' => 'Иванов Иван', 'licenseNumber' => '12 34 567890', 'licenseIssuedAt' => '2020-01-01',
            'snils' => '112-233-445 95',
            'base' => ['country' => 'Россия', 'city' => 'Москва', 'street' => 'База', 'house' => '1'],
            'destinations' => [['location' => ['country' => 'Россия', 'city' => 'Москва', 'street' => 'Склад', 'house' => '2'], 'distance' => '10', 'enterprise' => '  Предприятие  ']],
        ];
    }

    /** @param array<string, mixed> $data */
    private function post(array $data): void
    {
        $this->client->request('POST', '/cabinet/waybills/profile/edit', ['_token' => $this->token] + $data);
    }
}
