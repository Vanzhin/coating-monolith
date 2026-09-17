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
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Ramsey\Uuid\Uuid;

#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class AuditOnFlushListener
{
    /** @var list<AuditEntry> */
    private array $pending = [];

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

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->capture($em, $entity, AuditAction::Created, $uow->getEntityChangeSet($entity), $actor, $now, true);
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->capture($em, $entity, AuditAction::Updated, $uow->getEntityChangeSet($entity), $actor, $now, false);
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            $this->capture($em, $entity, AuditAction::Deleted, [], $actor, $now, true);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pending) {
            return;
        }
        $entries = $this->pending;
        $this->pending = []; // сброс ДО flush; AuditEntry/TrackedClass не в конфиге → не аудируются, рекурсии нет

        $em = $args->getObjectManager();
        foreach ($entries as $entry) {
            $em->persist($entry);
        }
        $em->flush();
    }

    /** @param array<string, array{0: mixed, 1: mixed}> $changeSet */
    private function capture(EntityManagerInterface $em, object $entity, AuditAction $action, array $changeSet, string $actor, \DateTimeImmutable $now, bool $recordEvenIfEmpty): void
    {
        $meta = $em->getClassMetadata($entity::class);
        $class = $meta->getName(); // реальный класс (разворачивает прокси)
        $tracked = array_keys($this->policy->trackedFields($class));
        if ([] === $tracked) {
            return; // класс не аудируется
        }

        $changes = $this->buildChanges($changeSet, $tracked);
        if ($changes->isEmpty() && !$recordEvenIfEmpty) {
            return; // update без изменений в отслеживаемых полях
        }

        $id = implode(':', array_map(static fn ($v): string => (string) $v, $meta->getIdentifierValues($entity)));
        $this->pending[] = new AuditEntry(Uuid::uuid4()->toString(), $class, $id, $action, $changes, $actor, $now);
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
