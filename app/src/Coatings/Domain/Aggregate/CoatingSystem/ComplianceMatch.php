<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

final readonly class ComplianceMatch implements \JsonSerializable
{
    public function __construct(
        public ComplianceStandard $standard,
        public string $category,
        public string $durability,
    ) {
    }

    /**
     * @return array{standard: string, category: string, durability: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'standard' => $this->standard->value,
            'category' => $this->category,
            'durability' => $this->durability,
        ];
    }

    /**
     * @param array{standard: string, category: string, durability: string} $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            ComplianceStandard::from($row['standard']),
            $row['category'],
            $row['durability'],
        );
    }
}
