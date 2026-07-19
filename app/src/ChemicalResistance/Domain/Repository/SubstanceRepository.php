<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Repository;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\Shared\Domain\Repository\PaginationResult;
use Symfony\Component\Uid\Uuid;

interface SubstanceRepository
{
    public function save(Substance $s): void;
    public function remove(Substance $s): void;
    public function find(Uuid $id): ?Substance;
    public function findByCanonicalNameKey(string $key): ?Substance;
    public function findByCas(CasNumber $cas): ?Substance;

    /**
     * @param list<string> $ids
     * @return list<Substance>
     */
    public function findAllByIds(array $ids): array;

    public function findByFilter(SubstancesFilter $filter): PaginationResult;
}
