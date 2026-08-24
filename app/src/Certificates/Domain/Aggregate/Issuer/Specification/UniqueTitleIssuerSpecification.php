<?php

declare(strict_types=1);

namespace App\Certificates\Domain\Aggregate\Issuer\Specification;

use App\Certificates\Domain\Aggregate\Issuer\Issuer;
use App\Certificates\Domain\Repository\IssuerRepositoryInterface;
use App\Shared\Domain\Specification\SpecificationInterface;
use App\Shared\Infrastructure\Exception\AppException;

class UniqueTitleIssuerSpecification implements SpecificationInterface
{
    public function __construct(private readonly IssuerRepositoryInterface $repository)
    {
    }

    public function satisfy(Issuer $issuer): void
    {
        $existing = $this->repository->findOneByTitle($issuer->getTitle());
        if (null !== $existing && $existing->getId() !== $issuer->getId()) {
            throw new AppException(sprintf('Издатель «%s» уже существует.', $issuer->getTitle()));
        }
    }
}
