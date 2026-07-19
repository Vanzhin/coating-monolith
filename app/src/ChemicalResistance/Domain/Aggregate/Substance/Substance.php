<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Domain\Aggregate\Substance;

use App\ChemicalResistance\Domain\Aggregate\Substance\Specification\SubstanceSpecification;
use App\ChemicalResistance\Domain\Service\SubstanceNameNormalizer;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

class Substance extends Aggregate
{
    public readonly Uuid $id;
    private string $canonicalName;
    private string $canonicalNameKey;
    private ?CasNumber $cas = null;
    /** @var StringCollection — hydrated from JSONB via StringCollectionType */
    private StringCollection $aliases;
    /**
     * Инжектится через InitSpecificationOnPostLoadListener на postLoad
     * или через конструктор при создании нового вещества.
     */
    private SubstanceSpecification $specification;

    public function __construct(
        Uuid $id,
        string $canonicalName,
        ?CasNumber $cas,
        StringCollection $aliases,
        SubstanceSpecification $specification,
    ) {
        $this->id = $id;
        $this->specification = $specification;
        $this->aliases = new StringCollection();
        $this->setCanonicalName($canonicalName);
        $this->setCas($cas);
        foreach ($aliases as $a) {
            $this->addAlias($a);
        }
    }

    public function getId(): string
    {
        return $this->id->toRfc4122();
    }

    public function getCanonicalName(): string
    {
        return $this->canonicalName;
    }

    public function getCanonicalNameKey(): string
    {
        return $this->canonicalNameKey;
    }

    public function getCas(): ?CasNumber
    {
        return $this->cas;
    }

    public function getAliases(): StringCollection
    {
        return $this->aliases;
    }

    public function setCanonicalName(string $name): void
    {
        $name = trim($name);
        if ('' === $name) {
            throw new AppException('Название вещества не может быть пустым.');
        }
        AssertService::maxLength($name, 200);
        $this->canonicalName = $name;
        $this->canonicalNameKey = SubstanceNameNormalizer::normalize($name);
        $this->specification->uniqueName->satisfy($this);
    }

    public function setCas(?CasNumber $cas): void
    {
        $this->cas = $cas;
        $this->specification->uniqueCas->satisfy($this);
    }

    public function addAlias(string $alias): void
    {
        $alias = trim($alias);
        if ('' === $alias) {
            return;
        }
        AssertService::maxLength($alias, 200);
        $key = SubstanceNameNormalizer::normalize($alias);
        if ($key === $this->canonicalNameKey) {
            return;
        }
        foreach ($this->aliases as $existing) {
            if (SubstanceNameNormalizer::normalize($existing) === $key) {
                return;
            }
        }
        $this->aliases = new StringCollection(...$this->aliases->getList(), ...[$alias]);
    }

    public function replaceAliases(array $aliases): void
    {
        $this->aliases = new StringCollection();
        foreach ($aliases as $a) {
            $this->addAlias($a);
        }
    }

    public function removeAlias(string $alias): void
    {
        $key = SubstanceNameNormalizer::normalize($alias);
        $kept = array_values(array_filter(
            $this->aliases->getList(),
            fn (string $a) => SubstanceNameNormalizer::normalize($a) !== $key,
        ));
        $this->aliases = new StringCollection(...$kept);
    }

    public function hasName(string $probe): bool
    {
        $key = SubstanceNameNormalizer::normalize($probe);
        if ($key === $this->canonicalNameKey) {
            return true;
        }
        foreach ($this->aliases as $a) {
            if (SubstanceNameNormalizer::normalize($a) === $key) {
                return true;
            }
        }

        return false;
    }

    /** canonical + aliases + optional CAS — то, что попадёт в FTS-вектор. */
    public function getSearchableNames(): StringCollection
    {
        $items = [$this->canonicalName, ...$this->aliases->getList()];
        if (null !== $this->cas) {
            $items[] = $this->cas->value;
        }

        return new StringCollection(...$items);
    }
}
