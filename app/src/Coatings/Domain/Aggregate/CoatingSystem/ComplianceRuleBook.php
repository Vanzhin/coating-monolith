<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

final class ComplianceRuleBook
{
    /** @return list<ComplianceRule> */
    public static function rules(): array
    {
        // Наполняется в Task 6 (правила ISO 12944 из таблиц B.2..B.5).
        return [];
    }
}
