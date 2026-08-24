<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

/**
 * Одно соответствие системы стандарту для показа: код стандарта + его название (для группировки),
 * готовая компактная подпись бейджа (label) и сырые значения осей (primary/secondary) — для данных
 * и JSON-payload'а модалки. label формируется стандартом (facets), поэтому Twig/JS не знают формата.
 */
final readonly class ComplianceMatchDTO
{
    public function __construct(
        public string $standard,
        public string $standardTitle,
        public string $label,
        public string $primary,
        public string $secondary,
    ) {
    }
}
