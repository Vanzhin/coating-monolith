<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Cache;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceEvaluator;
use Doctrine\DBAL\Connection;

final class CoatingSystemComplianceCacheRepository
{
    public function __construct(private readonly Connection $conn)
    {
    }

    public function rewrite(CoatingSystem $system, ComplianceEvaluator $evaluator): void
    {
        $this->conn->executeStatement(
            'DELETE FROM coating_system_compliance WHERE system_id = :id',
            ['id' => $system->getId()],
        );
        foreach ($system->complianceMatches($evaluator) as $match) {
            $this->conn->executeStatement(
                'INSERT INTO coating_system_compliance (system_id, standard, category, durability)
                 VALUES (:id, :std, :cat, :dur)',
                [
                    'id' => $system->getId(),
                    'std' => $match->standard->value,
                    'cat' => $match->category,
                    'dur' => $match->durability,
                ],
            );
        }
    }

    public function delete(string $systemId): void
    {
        $this->conn->executeStatement(
            'DELETE FROM coating_system_compliance WHERE system_id = :id',
            ['id' => $systemId],
        );
    }
}
