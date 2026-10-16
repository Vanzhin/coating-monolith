<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Dashboard;

/** Группа требования в детали человека: имя + тип + строки. */
final class RequirementGroupDTO
{
    public string $requirementId;
    public string $name;
    /** material|non_material */
    public string $type;
    public string $typeLabel;
    /** Счёт позиций группы по бакетам — для разреза «по требованиям» (единица = позиция, не группа). */
    public BucketCountsDTO $counts;
    /** @var list<ObligationBucketRowDTO> */
    public array $rows = [];
}
