<?php

declare(strict_types=1);

namespace App\Reports\Application\DTO\Counterparties;

use App\Reports\Domain\Aggregate\Counterparty\Counterparty;

class CounterpartyDTOTransformer
{
    public function fromEntity(Counterparty $counterparty): CounterpartyDTO
    {
        $dto = new CounterpartyDTO();
        $dto->id = $counterparty->getId();
        $dto->title = $counterparty->getTitle();
        $dto->description = $counterparty->getDescription();

        return $dto;
    }

    /**
     * @param iterable<Counterparty> $counterparties
     *
     * @return list<CounterpartyDTO>
     */
    public function fromEntityList(iterable $counterparties): array
    {
        $dtos = [];
        foreach ($counterparties as $counterparty) {
            $dtos[] = $this->fromEntity($counterparty);
        }

        return $dtos;
    }
}
