<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use App\Shared\Domain\Audit\AuditEntry;
use App\Shared\Domain\Audit\TrackedClass;
use Doctrine\ORM\EntityManagerInterface;

/** Кандидаты для аудита и их поля — из Doctrine-метаданных. Домен об аудите не знает. */
final class AuditableRegistry
{
    private const INTERNAL = [AuditEntry::class, TrackedClass::class];
    private const TECHNICAL_FIELDS = ['id', 'version'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<class-string> */
    public function auditableClasses(): array
    {
        $classes = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta->isMappedSuperclass || in_array($meta->getName(), self::INTERNAL, true)) {
                continue;
            }
            $classes[] = $meta->getName();
        }

        return $classes;
    }

    /** @return list<string> */
    public function mappedFields(string $class): array
    {
        return array_values(array_filter(
            $this->em->getClassMetadata($class)->getFieldNames(),
            static fn (string $f): bool => !in_array($f, self::TECHNICAL_FIELDS, true),
        ));
    }
}
