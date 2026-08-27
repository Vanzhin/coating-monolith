<?php

declare(strict_types=1);

namespace App\Certificates\Application\DTO\Issuers;

use App\Certificates\Domain\Aggregate\Issuer\Issuer;

class IssuerDTOTransformer
{
    public function fromEntity(Issuer $issuer): IssuerDTO
    {
        $dto = new IssuerDTO();
        $dto->id = $issuer->getId();
        $dto->title = $issuer->getTitle();

        return $dto;
    }

    /**
     * @param iterable<Issuer> $issuers
     *
     * @return list<IssuerDTO>
     */
    public function fromEntityList(iterable $issuers): array
    {
        $dtos = [];
        foreach ($issuers as $issuer) {
            $dtos[] = $this->fromEntity($issuer);
        }

        return $dtos;
    }
}
