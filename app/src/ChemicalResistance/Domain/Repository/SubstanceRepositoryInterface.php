<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Repository;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\Shared\Domain\Repository\PaginationResult;

interface SubstanceRepositoryInterface
{
    public function add(Substance $s): void;
    public function remove(Substance $s): void;
    public function findOneById(string $id): ?Substance;
    public function findByCanonicalNameKey(string $key): ?Substance;
    public function findByCas(CasNumber $cas): ?Substance;

    /**
     * @param list<string> $ids
     * @return list<Substance>
     */
    public function findAllByIds(array $ids): array;

    public function findByFilter(SubstancesFilter $filter): PaginationResult;
}
