<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Controller\Vehicle;

use App\Shared\Domain\Aggregate\ValueObject\PositiveNumber;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Application\DTO\VehicleDTO;
use App\Waybills\Application\UseCase\Command\CreateVehicle\CreateVehicleCommand;
use App\Waybills\Application\UseCase\Command\UpdateVehicle\UpdateVehicleCommand;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Infrastructure\Mapper\LocationFormMapper;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

final class VehicleFormMapper
{
    public function __construct(private readonly LocationFormMapper $locationMapper)
    {
    }

    /** @return array<string, string> */
    public function newVehicleInput(): array
    {
        return [
            'model' => '', 'registrationNumber' => '', 'consumptionRate' => '',
            'defaultFuelType' => FuelType::Ai95->value, 'initialAt' => '',
            'initialLocationId' => '', 'initialCountry' => '', 'initialCity' => '', 'initialCityType' => '', 'initialStreet' => '', 'initialHouse' => '',
            'initialEnterprise' => '',
            'initialOdometer' => '', 'initialFuelRemaining' => '',
        ];
    }

    /**
     * @param array<string, string> $input
     * @param array<mixed>          $fuelTypes
     */
    public function createVehicleCommand(array $input, array $fuelTypes): CreateVehicleCommand
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $input['initialAt']);
        if (false === $date || $date->format('Y-m-d\TH:i') !== $input['initialAt']) {
            throw new AppException('Укажите корректные дату и время начала истории автомобиля.');
        }

        try {
            $fuelRemaining = BigDecimal::of(trim($input['initialFuelRemaining']));
        } catch (MathException $exception) {
            throw new AppException('Укажите корректный начальный остаток топлива.', previous: $exception);
        }

        $location = $this->locationMapper->fromArray([
            'id' => $input['initialLocationId'], 'country' => $input['initialCountry'], 'city' => $input['initialCity'], 'cityType' => $input['initialCityType'],
            'street' => $input['initialStreet'], 'house' => $input['initialHouse'], 'enterprise' => $input['initialEnterprise'],
        ]);

        return new CreateVehicleCommand(
            $input['model'], $input['registrationNumber'], $date, $location,
            $this->odometer($input['initialOdometer']), $fuelRemaining, $this->consumptionRate($input['consumptionRate']),
            $this->fuelType($input['defaultFuelType']), ...$this->selectedFuelTypes($fuelTypes),
        );
    }

    /**
     * @param array<string, string> $input
     * @param array<mixed>          $fuelTypes
     */
    public function updateVehicleCommand(string $id, array $input, array $fuelTypes): UpdateVehicleCommand
    {
        return new UpdateVehicleCommand(
            $id, $input['model'], $input['registrationNumber'], $this->consumptionRate($input['consumptionRate']),
            $this->fuelType($input['defaultFuelType']), ...$this->selectedFuelTypes($fuelTypes),
        );
    }

    /**
     * @param array<mixed> $fuelTypes
     *
     * @return list<FuelType>
     */
    private function selectedFuelTypes(array $fuelTypes): array
    {
        $selected = [];
        foreach ($fuelTypes as $fuelType) {
            if (!is_string($fuelType)) {
                throw new AppException('Укажите корректные марки топлива.');
            }
            $selected[] = $this->fuelType($fuelType);
        }

        return $selected;
    }

    private function fuelType(string $value): FuelType
    {
        return FuelType::tryFrom($value) ?? throw new AppException('Укажите допустимую марку топлива.');
    }

    /** @return array<string, string> */
    public function vehicleInput(?VehicleDTO $vehicle): array
    {
        return [
            'model' => $vehicle->model ?? '',
            'registrationNumber' => strtoupper($vehicle->registrationNumber ?? ''),
            'defaultFuelType' => $vehicle->defaultFuelType ?? '',
            'consumptionRate' => null === $vehicle?->consumptionRate ? '' : (string) $vehicle->consumptionRate,
        ];
    }

    public function consumptionRate(string $input): ?PositiveNumber
    {
        if ('' === trim($input)) {
            return null;
        }

        return new PositiveNumber($this->number($input));
    }

    private function odometer(string $input): int
    {
        $value = filter_var(trim($input), FILTER_VALIDATE_INT);
        if (false === $value) {
            throw new AppException('Укажите целое показание одометра.');
        }

        return $value;
    }

    private function number(string $input): float
    {
        $input = trim($input);
        if (!is_numeric($input) || !is_finite((float) $input)) {
            throw new AppException('Укажите корректное число.');
        }

        return (float) $input;
    }
}
