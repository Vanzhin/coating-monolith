<?php

declare(strict_types=1);

namespace App\Reports\Application\DTO\Projects;

class ProjectDTO
{
    public string $id;
    public string $title;
    public ?string $description = null;
    public string $counterpartyId;
    public string $counterpartyTitle;
}
