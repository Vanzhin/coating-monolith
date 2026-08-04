<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

/**
 * Одно соответствие системы стандарту: стандарт + класс коррозивности + долговечность.
 * Голый data-класс; публичные поля сериализуются в тот же shape, что и раньше
 * ({standard, category, durability}) — payload карточки и preview-модалки не меняются.
 */
final readonly class ComplianceMatchDTO
{
    public function __construct(
        public string $standard,
        public string $category,
        public string $durability,
    ) {
    }
}
