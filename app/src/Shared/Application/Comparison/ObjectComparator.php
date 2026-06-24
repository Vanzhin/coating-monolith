<?php

declare(strict_types=1);

namespace App\Shared\Application\Comparison;

use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Type-agnostic сервис сравнения. Достаёт значения по PropertyAccess-путям из конфига
 * и для каждого поля возвращает строку с флагом «отличаются ли значения».
 *
 * Подписи, единицы, форматирование — забота вызывающего слоя (controller/template).
 */
final readonly class ObjectComparator
{
    public function __construct(private PropertyAccessorInterface $propertyAccessor)
    {
    }

    public function compare(ComparisonConfig $config, object ...$objects): ComparisonResult
    {
        if (count($objects) < 2) {
            throw new AppException('Нужно минимум 2 объекта для сравнения.');
        }
        $class = $objects[0]::class;
        foreach ($objects as $obj) {
            if ($obj::class !== $class) {
                throw new AppException(sprintf(
                    'Все объекты должны быть одного класса; получены %s и %s.',
                    $class,
                    $obj::class,
                ));
            }
        }

        $rows = [];
        foreach ($config->fields as $field) {
            $values = array_map(
                fn(object $obj) => $this->propertyAccessor->getValue($obj, $field),
                $objects,
            );
            // SORT_REGULAR глубоко сравнивает VO/массивы по значениям свойств.
            $isDifferent = count(array_unique($values, SORT_REGULAR)) > 1;
            $rows[] = new ComparisonRow($field, $values, $isDifferent);
        }

        return new ComparisonResult($rows);
    }
}
