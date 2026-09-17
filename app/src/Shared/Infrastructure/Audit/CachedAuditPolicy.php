<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use App\Shared\Domain\Audit\AuditPolicyInterface;
use App\Shared\Domain\Audit\TrackedClassRepositoryInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Читает карту поле→подпись из конфига, кэширует (cache.app) + мемоизирует в запросе. */
final class CachedAuditPolicy implements AuditPolicyInterface
{
    /** @var array<string, array<string, string>> */
    private array $memo = [];

    public function __construct(
        private readonly TrackedClassRepositoryInterface $repo,
        private readonly CacheInterface $cache,
    ) {
    }

    public function trackedFields(string $entityClass): array
    {
        if (isset($this->memo[$entityClass])) {
            return $this->memo[$entityClass];
        }

        return $this->memo[$entityClass] = $this->cache->get(
            $this->key($entityClass),
            fn (ItemInterface $item): array => $this->repo->findByClass($entityClass)?->fields() ?? [],
        );
    }

    public function invalidate(string $entityClass): void
    {
        unset($this->memo[$entityClass]);
        $this->cache->delete($this->key($entityClass));
    }

    private function key(string $entityClass): string
    {
        return 'audit.tracked.'.str_replace('\\', '.', $entityClass);
    }
}
