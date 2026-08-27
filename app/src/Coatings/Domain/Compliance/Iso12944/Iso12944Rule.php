<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Compliance\Iso12944;

use App\Coatings\Domain\Aggregate\Coating\CoatingBase;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Coatings\Domain\Compliance\ComplianceStandard;

final readonly class Iso12944Rule
{
    /**
     * @param list<CoatingBase>|null $primerBinders допустимые основания грунта; null — без ограничения
     *                                              (правила ГОСТ 34667.9 для CX/Im4 связующие не задают)
     * @param list<CoatingBase>|null $otherBinders  допустимые основания последующих слоёв; null — без
     *                                              ограничения. Пустой список [] = «последующих слоёв нет»
     */
    public function __construct(
        public ComplianceStandard $standard,
        public Substrate $substrate,
        public string $category,
        public string $durability,
        public PrimerType $primerType,
        public int $mnoc,
        public int $ndft,
        public ?array $primerBinders,
        public ?array $otherBinders,
    ) {
    }
}
