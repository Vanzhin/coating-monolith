<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Twig;

use App\Coatings\Application\Service\CoatingCompareMatrixBuilder;
use App\Coatings\Application\Service\CoatingTimeMatrixBuilder;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig-функции для matrix-таблиц времени высыхания:
 *  - coating_time_matrix(coating)     — один subject, preview-модалка;
 *  - coating_compare_matrix(subjects) — N subject'ов, compare-страница.
 */
final class CoatingMatrixExtension extends AbstractExtension
{
    public function __construct(
        private readonly CoatingTimeMatrixBuilder $timeBuilder,
        private readonly CoatingCompareMatrixBuilder $compareBuilder,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('coating_time_matrix', [$this->timeBuilder, 'build']),
            new TwigFunction('coating_compare_matrix', [$this->compareBuilder, 'build']),
        ];
    }
}
