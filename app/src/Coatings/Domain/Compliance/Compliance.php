<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance;

/**
 * Одно «соответствие» системы покрытий требованиям стандарта — унифицированная единица
 * результата оценки. Две оси маркировки хранятся обобщённо:
 *   - ISO 12944: primary = категория коррозивности (C3), secondary = долговечность (H);
 *   - СП 28.13330: primary = степень агрессивности, secondary = условия эксплуатации.
 * Ложится 1-в-1 в строку проекции coating_system_compliance (primary→category, secondary→durability).
 */
final readonly class Compliance
{
    public function __construct(
        public ComplianceStandard $standard,
        public string $primary,
        public ?string $secondary,
    ) {
    }

    /**
     * @return array{standard: string, primary: string, secondary: string|null}
     */
    public function toArray(): array
    {
        return [
            'standard' => $this->standard->value,
            'primary' => $this->primary,
            'secondary' => $this->secondary,
        ];
    }

    /**
     * @param array{standard: string, primary: string, secondary: string|null} $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            ComplianceStandard::from($row['standard']),
            $row['primary'],
            $row['secondary'],
        );
    }
}
