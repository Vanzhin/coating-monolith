<?php
declare(strict_types=1);
namespace App\Shared\Infrastructure\Database\DBAL;

use App\Shared\Domain\Audit\ChangeSet;

final class ChangeSetType extends AbstractJsonObjectType
{
    public const NAME = 'audit_change_set';
    public function getName(): string { return self::NAME; }
    protected function valueClass(): string { return ChangeSet::class; }
    /** @param array<mixed> $raw */
    protected function hydrate(array $raw): ChangeSet { return ChangeSet::fromArray($raw); }
}
