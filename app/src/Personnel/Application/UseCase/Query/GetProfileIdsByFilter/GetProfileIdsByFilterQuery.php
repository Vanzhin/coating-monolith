<?php

declare(strict_types=1);

namespace App\Personnel\Application\UseCase\Query\GetProfileIdsByFilter;

use App\Shared\Application\Query\Query;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

/** id профилей по должностям и/или поиску ФИО — для пред-сужения набора в других контекстах (дашборд). */
final readonly class GetProfileIdsByFilterQuery extends Query
{
    public function __construct(
        public StringCollection $positionIds = new StringCollection(),
        public ?string $search = null,
    ) {
    }
}
