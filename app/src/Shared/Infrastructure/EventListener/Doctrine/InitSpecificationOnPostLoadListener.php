<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener\Doctrine;

use App\Coatings\Domain\Aggregate\Coating\Coating;
use App\Coatings\Domain\Aggregate\Coating\Specification\CoatingSpecification;
use App\Coatings\Domain\Aggregate\Manufacturer\Manufacturer;
use App\Coatings\Domain\Aggregate\Manufacturer\Specification\ManufacturerSpecification;
use App\Customers\Domain\Aggregate\CustomerWaybill\CustomerWaybillItemType;
use App\Shared\Domain\Specification\SpecificationInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;

#[AsDoctrineListener(event: Events::postLoad)]
final readonly class InitSpecificationOnPostLoadListener
{
    // todo надо сделать один на все сущности, но ContainerBagInterface $container не видит спецификацию в параметрах
    public function __construct(private ContainerBagInterface     $container,
                                private ManufacturerSpecification $manufacturerSpecification,
                                private CoatingSpecification      $coatingSpecification,
    )
    {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();

        $reflect = new \ReflectionClass($entity);

        foreach ($reflect->getProperties() as $property) {
            $type = $property->getType();

            if (is_null($type) || $property->isInitialized($entity)) {
                continue;
            }

            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                // initialize specifications
                $interfaces = class_implements($type->getName());
                if (isset($interfaces[SpecificationInterface::class])) {
//                    $property->setValue($entity, $this->container->get($type->getName()));
                    $property->setValue($entity, $this->match($entity));

                }
            }
        }
    }

    private function match(object $entity): SpecificationInterface
    {
        return match ($entity::class) {
            Manufacturer::class => $this->manufacturerSpecification,
            Coating::class => $this->coatingSpecification,
        };
    }
}
