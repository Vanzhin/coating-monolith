<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Dashboard;

use App\Compliance\Domain\Type\ComplianceBucket;

/** Счётчики по бакетам — общий примитив: сводка-чипы человека (по обязанностям) и разрезы (по людям). */
final class BucketCountsDTO
{
    public int $ok = 0;
    public int $soon = 0;
    public int $overdue = 0;
    public int $missing = 0;

    public function add(ComplianceBucket $bucket): void
    {
        match ($bucket) {
            ComplianceBucket::Ok => ++$this->ok,
            ComplianceBucket::Soon => ++$this->soon,
            ComplianceBucket::Overdue => ++$this->overdue,
            ComplianceBucket::Missing => ++$this->missing,
        };
    }

    public function total(): int
    {
        return $this->ok + $this->soon + $this->overdue + $this->missing;
    }

    public function problems(): int
    {
        return $this->overdue + $this->missing;
    }
}
