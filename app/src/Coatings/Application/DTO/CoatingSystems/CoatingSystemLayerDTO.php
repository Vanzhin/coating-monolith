<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

class CoatingSystemLayerDTO
{
    public string $id;
    public int $position;
    public int $dft;
    public string $coatingId;
    public string $coatingTitle;
    public string $coatingBase;
    public string $coatingBaseTitle;
    public bool $isZincRich;
}
