<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Audit;

use App\Shared\Domain\Audit\AuditFieldKind;
use App\Shared\Domain\Audit\AuditPolicyInterface;
use App\Shared\Domain\Audit\TrackedClassRepositoryInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/** Читает карту поле→подпись/вид из конфига, кэширует (cache.app) + мемоизирует в запросе. */
final class CachedAuditPolicy implements AuditPolicyInterface
{
    /** @var array<string, array<string, string>> */
    private array $memo = [];

    /** @var array<string, array<string, string>> */
    private array $kindMemo = [];

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

    public function fieldKinds(string $entityClass): array
    {
        // В кэше/мемо — сериализуемые строки; в enum конвертируем СНАРУЖИ callback'а.
        return array_map(AuditFieldKind::fromString(...), $this->cachedKinds($entityClass));
    }

    public function invalidate(string $entityClass): void
    {
        unset($this->memo[$entityClass], $this->kindMemo[$entityClass]);
        $this->cache->delete($this->key($entityClass));
        $this->cache->delete($this->kindKey($entityClass));
    }

    /** @return array<string, string> */
    private function cachedKinds(string $entityClass): array
    {
        if (isset($this->kindMemo[$entityClass])) {
            return $this->kindMemo[$entityClass];
        }

        return $this->kindMemo[$entityClass] = $this->cache->get(
            $this->kindKey($entityClass),
            fn (ItemInterface $item): array => $this->repo->findByClass($entityClass)?->kinds() ?? [],
        );
    }

    private function key(string $entityClass): string
    {
        return 'audit.tracked.'.str_replace('\\', '.', $entityClass);
    }

    private function kindKey(string $entityClass): string
    {
        return 'audit.tracked.kind.'.str_replace('\\', '.', $entityClass);
    }
}
