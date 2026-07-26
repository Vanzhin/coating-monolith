<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Factory;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceRuleBook;

final class ComplianceEvaluatorFactory
{
    public static function create(): ComplianceEvaluator
    {
        return new ComplianceEvaluator(ComplianceRuleBook::rules());
    }
}
