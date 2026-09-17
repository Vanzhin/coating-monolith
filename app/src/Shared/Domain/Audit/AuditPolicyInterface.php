<?php
declare(strict_types=1);
namespace App\Shared\Domain\Audit;

interface AuditPolicyInterface
{
    /** @return array<string, string> поле → подпись (пусто = класс не аудируется) */
    public function trackedFields(string $entityClass): array;

    public function invalidate(string $entityClass): void;
}
