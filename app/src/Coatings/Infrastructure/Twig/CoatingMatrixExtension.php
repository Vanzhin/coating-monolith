<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Twig;

use App\Coatings\Application\Service\CoatingTimeMatrixBuilder;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig-функция coating_time_matrix(coating): собирает matrix-таблицу
 * времени высыхания в preview-модалке. Логика в
 * App\Coatings\Application\Service\CoatingTimeMatrixBuilder.
 */
final class CoatingMatrixExtension extends AbstractExtension
{
    public function __construct(private readonly CoatingTimeMatrixBuilder $builder)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('coating_time_matrix', [$this->builder, 'build']),
        ];
    }
}
