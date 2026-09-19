<?php

declare(strict_types=1);

namespace App\Reports\Domain\Hint;

/** Виды мягких подсказок при заполнении слоя (не блокируют сохранение). */
enum LayerWarningCode: string
{
    case DftBelowMin = 'dft_below_min';
    case DftAboveMax = 'dft_above_max';
    case SurfaceBelowApplicationTemp = 'surface_below_application_temp';
    case CondensationRisk = 'condensation_risk';
}
