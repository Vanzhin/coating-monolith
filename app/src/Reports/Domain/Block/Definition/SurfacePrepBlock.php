<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block\Definition;

use App\Reports\Domain\Block\BlockDefinition;
use App\Reports\Domain\Block\BlockKey;
use App\Reports\Domain\Block\Field;
use App\Reports\Domain\Block\FieldType;
use App\Shared\Domain\Aggregate\Enum\DedustingClass;
use App\Shared\Domain\Aggregate\Enum\PreparationDegree;
use App\Shared\Domain\Aggregate\Enum\RustGrade;
use App\Shared\Domain\Aggregate\Enum\SurfaceRoughness;

final class SurfacePrepBlock implements BlockDefinition
{
    public function key(): BlockKey
    {
        return BlockKey::SurfacePrep;
    }

    public function title(): string
    {
        return 'Подготовка поверхности';
    }

    public function fields(): array
    {
        return [
            new Field('rustGrade', FieldType::Enum, 'Класс ржавления', required: true, standard: 'ГОСТ Р ИСО 8501-1-2014', enum: RustGrade::class),
            new Field('prepDegree', FieldType::Enum, 'Степень подготовки', required: true, standard: 'ISO 8501-1', enum: PreparationDegree::class),
            new Field('blasting_media', FieldType::Text, 'Абразив'),
            new Field('roughness', FieldType::Enum, 'Шероховатость (профиль)', standard: 'ISO 8503-2', enum: SurfaceRoughness::class),
            new Field('dedusting', FieldType::Enum, 'Обеспыливание, класс', standard: 'ISO 8502-3', enum: DedustingClass::class),
        ];
    }
}
