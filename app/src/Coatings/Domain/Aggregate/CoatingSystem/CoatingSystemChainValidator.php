<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\CoatingSystem;

use App\Shared\Infrastructure\Exception\AppException;

class CoatingSystemChainValidator
{
    public function validate(CoatingSystem $system): void
    {
        $layers = array_values($system->getLayers()->toArray());
        $n = count($layers);
        for ($i = 0; $i < $n - 1; $i++) {
            $current = $layers[$i]->getCoating()->getBase();
            $next    = $layers[$i + 1]->getCoating()->getBase();
            if (!$current->canBecoveredBy($next)) {
                throw new AppException(sprintf(
                    'Слой %d (%s) несовместим со слоем %d (%s): поверх %s нельзя наносить %s.',
                    $i + 1, $current->title(),
                    $i + 2, $next->title(),
                    $current->title(), $next->title(),
                ));
            }
        }
    }
}
