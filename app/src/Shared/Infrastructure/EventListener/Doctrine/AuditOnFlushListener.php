<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener\Doctrine;

use App\Shared\Domain\Audit\AuditAction;
use App\Shared\Domain\Audit\AuditEntry;
use App\Shared\Domain\Audit\AuditPolicyInterface;
use App\Shared\Domain\Audit\ChangeSet;
use App\Shared\Domain\Audit\JsonDiff;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Shared\Domain\Security\SystemUser;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Ramsey\Uuid\Uuid;

/**
 * Пишет аудит-лог в ТОЙ ЖЕ транзакции, что и бизнес-запись: строки AuditEntry
 * планируются в onFlush через UnitOfWork::computeChangeSet, поэтому попадают в тот
 * же commit. Сбой вставки аудита откатывает и бизнес-запись — «нет записи без аудита»
 * (fail-closed, атомарно). Никакого postFlush/второго flush.
 * Класс/id — из Doctrine-метаданных (домен об аудите не знает).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class AuditOnFlushListener
{
    public function __construct(
        private readonly AuditPolicyInterface $policy,
        private readonly JsonDiff $jsonDiff,
        private readonly AuthUserFetcherInterface $auth,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $actor = $this->auth->isAuthenticated() ? $this->auth->getAuthUserId() : SystemUser::ID;
        $now = new \DateTimeImmutable();

        /** @var list<AuditEntry> $entries */
        $entries = [];
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $entry = $this->capture($em, $entity, AuditAction::Created, $uow->getEntityChangeSet($entity), $actor, $now, true);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $entry = $this->capture($em, $entity, AuditAction::Updated, $uow->getEntityChangeSet($entity), $actor, $now, false);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $entry = $this->capture($em, $entity, AuditAction::Deleted, [], $actor, $now, true);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        if ([] === $entries) {
            return;
        }

        // Планируем вставки в ТЕКУЩИЙ flush (та же транзакция). AuditEntry/TrackedClass
        // не в конфиге аудита → повторно не аудируются, рекурсии нет.
        $auditMeta = $em->getClassMetadata(AuditEntry::class);
        foreach ($entries as $entry) {
            $em->persist($entry);
            $uow->computeChangeSet($auditMeta, $entry);
        }
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private function capture(EntityManagerInterface $em, object $entity, AuditAction $action, array $changeSet, string $actor, \DateTimeImmutable $now, bool $recordEvenIfEmpty): ?AuditEntry
    {
        $meta = $em->getClassMetadata($entity::class);
        $class = $meta->getName();
        $tracked = array_keys($this->policy->trackedFields($class));
        if ([] === $tracked) {
            return null;
        }

        $changes = $this->buildChanges($changeSet, $tracked);
        if ($changes->isEmpty() && !$recordEvenIfEmpty) {
            return null;
        }

        $id = implode(':', array_map(static fn ($v): string => (string) $v, $meta->getIdentifierValues($entity)));

        return new AuditEntry(Uuid::uuid4()->toString(), $class, $id, $action, $changes, $actor, $now);
    }

    /**
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     * @param list<string>                             $tracked
     */
    private function buildChanges(array $changeSet, array $tracked): ChangeSet
    {
        $changes = [];
        foreach ($changeSet as $field => [$old, $new]) {
            if (!in_array($field, $tracked, true)) {
                continue;
            }
            $changes = [...$changes, ...$this->jsonDiff->diff($old, $new, $field)];
        }

        return new ChangeSet(...$changes);
    }
}
