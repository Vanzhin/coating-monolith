<?php

declare(strict_types=1);

namespace App\Tests\Unit\Waybills\Infrastructure\Controller\Vehicle;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Application\DTO\VehicleDTO;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Infrastructure\Controller\Vehicle\VehicleFormMapper;
use App\Waybills\Infrastructure\Mapper\LocationFormMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VehicleFormMapperTest extends TestCase
{
    public function test_displays_saved_registration_number_in_uppercase(): void
    {
        $vehicle = new VehicleDTO('id', 'Модель', 'a123bc77', [], null, null, null, new Location('Россия', 'База'));

        self::assertSame('A123BC77', (new VehicleFormMapper(new LocationFormMapper()))->vehicleInput($vehicle)['registrationNumber']);
    }

    public function test_create_command_preserves_initial_data_and_fuel_precision(): void
    {
        $mapper = new VehicleFormMapper(new LocationFormMapper());
        $input = array_replace($mapper->newVehicleInput(), [
            'model' => 'Модель', 'registrationNumber' => 'A123BC77',
            'initialAt' => '2026-01-02T10:30', 'initialCity' => 'Москва',
            'initialOdometer' => '123', 'initialFuelRemaining' => '30.00000000000000000001',
            'consumptionRate' => '8.5',
        ]);

        $command = $mapper->createVehicleCommand($input, ['АИ-92', 'АИ-95']);

        self::assertSame('Модель', $command->model);
        self::assertSame('A123BC77', $command->registrationNumber);
        self::assertSame('2026-01-02 10:30', $command->initialAt->format('Y-m-d H:i'));
        self::assertSame('Москва', $command->initialLocation->city?->name);
        self::assertSame(123, $command->initialOdometer);
        self::assertSame('30.00000000000000000001', (string) $command->initialFuelRemaining);
        self::assertSame(8.5, $command->consumptionRate?->value());
        self::assertSame(FuelType::Ai95, $command->defaultFuelType);
        self::assertSame([FuelType::Ai92, FuelType::Ai95], $command->fuelTypes);
    }

    public function test_update_command_uses_the_same_typed_fuel_fields(): void
    {
        $mapper = new VehicleFormMapper(new LocationFormMapper());
        $input = [
            'model' => 'Модель', 'registrationNumber' => 'A123BC77',
            'consumptionRate' => '8.5', 'defaultFuelType' => 'АИ-92',
        ];

        $command = $mapper->updateVehicleCommand('id', $input, ['АИ-92', 'АИ-95']);

        self::assertSame('id', $command->id);
        self::assertSame('A123BC77', $command->registrationNumber);
        self::assertSame(8.5, $command->consumptionRate?->value());
        self::assertSame(FuelType::Ai92, $command->defaultFuelType);
        self::assertSame([FuelType::Ai92, FuelType::Ai95], $command->fuelTypes);
    }

    #[DataProvider('invalidInputs')]
    public function test_rejects_invalid_form_representation(string $field, string $value, string $message): void
    {
        $mapper = new VehicleFormMapper(new LocationFormMapper());
        $input = array_replace($mapper->newVehicleInput(), [
            'model' => 'Модель', 'registrationNumber' => 'A123BC77',
            'initialAt' => '2026-01-02T10:30', 'initialCity' => 'Москва',
            'initialOdometer' => '123', 'initialFuelRemaining' => '30',
        ], [$field => $value]);

        $this->expectException(AppException::class);
        $this->expectExceptionMessage($message);
        $mapper->createVehicleCommand($input, ['АИ-95']);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidInputs(): iterable
    {
        yield 'date' => ['initialAt', '2026-02-30T10:30', 'дату и время'];
        yield 'missing time' => ['initialAt', '2026-01-02', 'дату и время'];
        yield 'fuel' => ['initialFuelRemaining', 'тридцать', 'начальный остаток топлива'];
        yield 'odometer' => ['initialOdometer', 'много', 'целое показание одометра'];
        yield 'fractional odometer' => ['initialOdometer', '123.5', 'целое показание одометра'];
        yield 'fractional odometer with comma' => ['initialOdometer', '123,5', 'целое показание одометра'];
        yield 'fuel type' => ['defaultFuelType', 'дизель', 'допустимую марку топлива'];
    }
}
