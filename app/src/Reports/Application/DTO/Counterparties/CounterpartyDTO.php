<?php

declare(strict_types=1);

namespace App\Reports\Application\DTO\Counterparties;

class CounterpartyDTO
{
    public string $id;
    public string $title;
    public ?string $tin = null;
    public ?string $description = null;
}
