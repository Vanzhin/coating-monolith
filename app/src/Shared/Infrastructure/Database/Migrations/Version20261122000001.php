<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
use Symfony\Component\Uid\Uuid;

final class Version20261122000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Waybills: связывание существующих адресных снимков со справочником точек';
    }

    public function up(Schema $schema): void
    {
        $profiles = $this->connection->fetchAllAssociative('SELECT id, owner_id, route_settings FROM waybills_driver_profile');
        foreach ($profiles as $profile) {
            $settings = json_decode((string) $profile['route_settings'], true, flags: JSON_THROW_ON_ERROR);
            $settings['base'] = $this->identify($profile['owner_id'], $settings['base']);
            foreach ($settings['destinations'] as &$destination) {
                $destination['location']['enterprise'] ??= $destination['enterprise'] ?? null;
                $destination['location'] = $this->identify($profile['owner_id'], $destination['location']);
            }
            unset($destination);
            $this->connection->update('waybills_driver_profile', ['route_settings' => json_encode($settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], ['id' => $profile['id']]);
        }

        $vehicles = $this->connection->fetchAllAssociative('SELECT v.id, v.initial_location, p.owner_id FROM waybills_vehicle v JOIN waybills_driver_profile p ON p.id = v.driver_id');
        foreach ($vehicles as $vehicle) {
            $location = json_decode((string) $vehicle['initial_location'], true, flags: JSON_THROW_ON_ERROR);
            $this->connection->update('waybills_vehicle', ['initial_location' => json_encode($this->identify($vehicle['owner_id'], $location), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], ['id' => $vehicle['id']]);
        }

        $trips = $this->connection->fetchAllAssociative('SELECT r.id, r.location, r.enterprise, p.owner_id FROM waybills_route_log r JOIN waybills_vehicle v ON v.id = r.vehicle_id JOIN waybills_driver_profile p ON p.id = v.driver_id');
        foreach ($trips as $trip) {
            $location = json_decode((string) $trip['location'], true, flags: JSON_THROW_ON_ERROR);
            $location['enterprise'] ??= $trip['enterprise'];
            $this->connection->update('waybills_route_log', ['location' => json_encode($this->identify($trip['owner_id'], $location), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)], ['id' => $trip['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Старые строковые представления населённых пунктов нельзя восстановить без потери типа.');
    }

    /** @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function identify(string $ownerId, array $snapshot): array
    {
        if (null !== ($snapshot['id'] ?? null)) {
            return $snapshot;
        }
        $city = $snapshot['city'] ?? null;
        $cityName = is_array($city) ? (string) ($city['name'] ?? '') : (string) $city;
        $cityType = is_array($city) ? ($city['type'] ?? null) : null;
        $values = [
            'owner_id' => $ownerId,
            'country' => (string) ($snapshot['country'] ?? ''),
            'city' => $cityName,
            'city_type' => $cityType,
            'street' => (string) ($snapshot['street'] ?? ''),
            'house' => (string) ($snapshot['house'] ?? ''),
            'enterprise' => $snapshot['enterprise'] ?? null,
            'latitude' => $snapshot['latitude'] ?? null,
            'longitude' => $snapshot['longitude'] ?? null,
        ];
        $conditions = [];
        foreach ($values as $field => $value) {
            $conditions[] = $field.' IS NOT DISTINCT FROM :'.$field;
        }
        $id = $this->connection->fetchOne('SELECT id FROM waybills_location WHERE '.implode(' AND ', $conditions).' LIMIT 1', $values);
        if (false === $id) {
            $id = (string) Uuid::v7();
            $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s.uP');
            $this->connection->executeStatement(
                'INSERT INTO waybills_location (id, owner_id, country, city, city_type, street, house, enterprise, latitude, longitude, created_at, updated_at)
                 VALUES (:id, :owner_id, :country, :city, :city_type, :street, :house, :enterprise, :latitude, :longitude, :created_at, :updated_at) ON CONFLICT DO NOTHING',
                ['id' => $id, ...$values, 'created_at' => $now, 'updated_at' => $now],
            );
            $id = $this->connection->fetchOne('SELECT id FROM waybills_location WHERE '.implode(' AND ', $conditions).' LIMIT 1', $values);
        }

        return [
            'id' => (string) $id,
            'country' => $values['country'],
            'city' => '' === $cityName ? null : ['name' => $cityName, 'type' => $cityType],
            'street' => $values['street'],
            'house' => $values['house'],
            'enterprise' => $values['enterprise'],
            'latitude' => $values['latitude'],
            'longitude' => $values['longitude'],
        ];
    }
}
