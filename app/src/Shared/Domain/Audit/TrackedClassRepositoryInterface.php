<?php

declare(strict_types=1);

namespace App\Shared\Domain\Audit;

interface TrackedClassRepositoryInterface
{
    public function findByClass(string $entityClass): ?TrackedClass;

    /** @return list<TrackedClass> */
    public function all(): array;

    public function save(TrackedClass $trackedClass): void;

    public function remove(TrackedClass $trackedClass): void;
}
