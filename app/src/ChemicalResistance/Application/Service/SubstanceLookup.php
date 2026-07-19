<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Application\Service;

use App\ChemicalResistance\Domain\Aggregate\Substance\CasNumber;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\UniqueCasSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\UniqueSubstanceNameSpecification;
use App\ChemicalResistance\Domain\Aggregate\Substance\Substance;
use App\ChemicalResistance\Domain\Repository\SubstanceRepositoryInterface;
use App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

final class SubstanceLookup
{
    public function __construct(private SubstanceRepositoryInterface $repo) {}

    public function findByNormalizedName(string $raw): ?Substance
    {
        $key = SubstanceNameNormalizer::normalize(trim($raw));
        return $this->repo->findByCanonicalNameKey($key);
    }

    public function findOrCreateByName(string $raw, ?CasNumber $cas = null, bool $persist = true): Substance
    {
        $raw = trim($raw);

        // 1. Prefer CAS match if given.
        if ($cas !== null) {
            $existing = $this->repo->findByCas($cas);
            if ($existing !== null) {
                if (!$existing->hasName($raw)) {
                    $existing->addAlias($raw);
                    if ($persist) { $this->repo->add($existing); }
                }
                return $existing;
            }
        }

        // 2. Try normalized name.
        $key = SubstanceNameNormalizer::normalize($raw);
        $existing = $this->repo->findByCanonicalNameKey($key);
        if ($existing !== null) {
            if (!$existing->hasName($raw)) {
                $existing->addAlias($raw);
                if ($persist) { $this->repo->add($existing); }
            }
            return $existing;
        }

        // 3. Create fresh.
        $sub = new Substance(Uuid::v4(), $raw, $cas, new StringCollection(), $this->spec());
        if ($persist) { $this->repo->add($sub); }
        return $sub;
    }

    private function spec(): SubstanceSpecification
    {
        return new SubstanceSpecification(
            new UniqueSubstanceNameSpecification($this->repo),
            new UniqueCasSpecification($this->repo),
        );
    }
}
