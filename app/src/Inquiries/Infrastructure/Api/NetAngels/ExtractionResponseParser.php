<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Api\NetAngels;

use App\Coatings\Domain\Aggregate\Coating\EnvironmentType;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Compliance\ComplianceStandard;
use App\Coatings\Domain\Compliance\Iso12944\IsoCorrosivityCategory;
use App\Coatings\Domain\Compliance\Iso12944\IsoDurability;
use App\Coatings\Domain\Repository\ThermalEnvironment;
use App\Inquiries\Application\DTO\ChemicalResistanceParams;
use App\Inquiries\Application\DTO\ExtractedParameters;
use App\Inquiries\Application\DTO\IntRangeDto;
use App\Inquiries\Application\DTO\ThermalParams;

/**
 * Сырой JSON модели → ExtractedParameters. Одна схема для всех провайдеров. Значения вне
 * enum проекта отбрасываются в null/[] и фиксируются в списке dropped (для показа инженеру).
 * Поле conflicts (заметки модели о расхождениях источников) пробрасывается как есть.
 * Температуры проверяются по границам ThermalExposureLimits (-200..1000).
 */
final class ExtractionResponseParser
{
    private const TEMP_MIN = -200;
    private const TEMP_MAX = 1000;

    /** @return array{parameters: ExtractedParameters, dropped: list<string>, conflicts: list<string>} */
    public function parse(string $json): array
    {
        $data = json_decode($json, true);
        $dropped = [];

        if (!\is_array($data)) {
            return ['parameters' => $this->empty(), 'dropped' => ['ответ модели — невалидный JSON'], 'conflicts' => []];
        }

        $chem = \is_array($data['chemicalResistance'] ?? null) ? $data['chemicalResistance'] : [];
        $thermal = \is_array($data['thermal'] ?? null) ? $data['thermal'] : [];

        $parameters = new ExtractedParameters(
            substrates: $this->enumList($data['substrates'] ?? null, static fn (string $v) => Substrate::tryFrom($v), 'substrates', $dropped),
            environment: $this->enumValue($data['environment'] ?? null, static fn (string $v) => EnvironmentType::tryFrom($v), 'environment', $dropped),
            specifiedCoatings: $this->names($data['specifiedCoatings'] ?? null),
            search: $this->nullableString($data['search'] ?? null),
            chemicalResistance: new ChemicalResistanceParams(
                substances: $this->names($chem['substances'] ?? null),
                maxTemperature: $this->tempInRange($chem['maxTemperature'] ?? null, 'chemicalResistance.maxTemperature', $dropped),
                peakTemperature: $this->tempInRange($chem['peakTemperature'] ?? null, 'chemicalResistance.peakTemperature', $dropped),
            ),
            thermal: new ThermalParams(
                maxTemperature: $this->tempInRange($thermal['maxTemperature'] ?? null, 'thermal.maxTemperature', $dropped),
                peakTemperature: $this->tempInRange($thermal['peakTemperature'] ?? null, 'thermal.peakTemperature', $dropped),
                environment: $this->enumValue($thermal['environment'] ?? null, static fn (string $v) => ThermalEnvironment::tryFrom($v), 'thermal.environment', $dropped),
            ),
            standard: $this->enumValue($data['standard'] ?? null, static fn (string $v) => ComplianceStandard::tryFrom($v), 'standard', $dropped),
            category: $this->enumValue($data['category'] ?? null, static fn (string $v) => IsoCorrosivityCategory::tryFrom($v), 'category', $dropped),
            durability: $this->enumValue($data['durability'] ?? null, static fn (string $v) => IsoDurability::tryFrom($v), 'durability', $dropped),
            applicationMinTemp: $this->range($data['applicationMinTemp'] ?? null),
            minApplicationTimeAt20: $this->range($data['minApplicationTimeAt20'] ?? null),
        );

        return ['parameters' => $parameters, 'dropped' => $dropped, 'conflicts' => $this->names($data['conflicts'] ?? null)];
    }

    private function empty(): ExtractedParameters
    {
        return new ExtractedParameters(
            [],
            null,
            [],
            null,
            new ChemicalResistanceParams([], null, null),
            new ThermalParams(null, null, null),
            null,
            null,
            null,
            null,
            null,
        );
    }

    /**
     * @param callable(string): ?object $tryFrom
     * @param list<string>              $dropped
     *
     * @return list<string>
     */
    private function enumList(mixed $raw, callable $tryFrom, string $field, array &$dropped): array
    {
        $out = [];
        foreach (\is_array($raw) ? $raw : [] as $value) {
            if (\is_string($value) && null !== $tryFrom($value)) {
                $out[] = $value;
            } elseif (null !== $value && '' !== $value) {
                $dropped[] = $field.': '.(\is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE));
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param callable(string): ?object $tryFrom
     * @param list<string>              $dropped
     */
    private function enumValue(mixed $raw, callable $tryFrom, string $field, array &$dropped): ?string
    {
        if (null === $raw || '' === $raw) {
            return null;
        }
        if (\is_string($raw) && null !== $tryFrom($raw)) {
            return $raw;
        }
        $dropped[] = $field.': '.(\is_string($raw) ? $raw : json_encode($raw, JSON_UNESCAPED_UNICODE));

        return null;
    }

    /** @return list<string> */
    private function names(mixed $raw): array
    {
        $out = [];
        foreach (\is_array($raw) ? $raw : [] as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                $out[] = trim($value);
            }
        }

        return $out;
    }

    private function nullableString(mixed $raw): ?string
    {
        return \is_string($raw) && '' !== trim($raw) ? trim($raw) : null;
    }

    /** @param list<string> $dropped */
    private function tempInRange(mixed $raw, string $field, array &$dropped): ?int
    {
        if (\is_int($raw) && $raw >= self::TEMP_MIN && $raw <= self::TEMP_MAX) {
            return $raw;
        }
        if (null !== $raw) {
            $dropped[] = $field.': '.json_encode($raw, JSON_UNESCAPED_UNICODE);
        }

        return null;
    }

    private function range(mixed $raw): ?IntRangeDto
    {
        if (!\is_array($raw)) {
            return null;
        }
        $from = \is_int($raw['from'] ?? null) ? $raw['from'] : null;
        $to = \is_int($raw['to'] ?? null) ? $raw['to'] : null;

        return null === $from && null === $to ? null : new IntRangeDto($from, $to);
    }
}
