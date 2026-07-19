<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener\Doctrine;

use App\Shared\Domain\Specification\SpecificationInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * После hydration Doctrine инициализирует все свойства-Specification агрегата
 * из service-locator (собран через #[AutowireLocator] по тегу
 * SpecificationInterface). Новые контексты подтягиваются автоматически —
 * достаточно чтобы Specification-класс имплементировал SpecificationInterface.
 */
#[AsDoctrineListener(event: Events::postLoad)]
final readonly class InitSpecificationOnPostLoadListener
{
    public function __construct(
        #[AutowireLocator('app.specification', defaultIndexMethod: null)]
        private ContainerInterface $specifications,
    ) {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        $reflect = new \ReflectionClass($entity);

        foreach ($reflect->getProperties() as $property) {
            $type = $property->getType();
            if (null === $type || $property->isInitialized($entity)) {
                continue;
            }
            if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();
            if (!$this->specifications->has($className)) {
                continue;
            }

            $property->setValue($entity, $this->specifications->get($className));
        }
    }
}
