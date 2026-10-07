<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Resolver;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Service\RecipientResolverInterface;
use App\Notifications\Domain\Type\ResolverKey;
use App\Shared\Infrastructure\Exception\AppException;

/** Реестр резолверов адресатов: по ключу стратегии из типа события отдаёт нужный резолвер. */
final class ResolverRegistry
{
    /** @var array<string, RecipientResolverInterface> */
    private array $byKey = [];

    /** @param iterable<RecipientResolverInterface> $resolvers */
    public function __construct(iterable $resolvers)
    {
        foreach ($resolvers as $resolver) {
            $this->byKey[$resolver->key()->value] = $resolver;
        }
    }

    public function for(ResolverKey $key): RecipientResolverInterface
    {
        return $this->byKey[$key->value] ?? throw new AppException('Нет резолвера: '.$key->value);
    }

    /** @return list<string> */
    public function resolve(NotifiableEvent $event): array
    {
        return $this->for($event->notificationType()->resolver())->resolve($event);
    }
}
