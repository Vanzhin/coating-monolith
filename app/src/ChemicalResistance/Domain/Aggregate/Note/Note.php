<?php

declare(strict_types=1);

namespace App\ChemicalResistance\Domain\Aggregate\Note;

use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Service\AssertService;
use Symfony\Component\Uid\Uuid;

class Note extends Aggregate
{
    public readonly Uuid $id;
    private string $title;
    private string $description;

    public function __construct(Uuid $id, string $title, string $description)
    {
        $this->id = $id;
        $this->setTitle($title);
        $this->setDescription($description);
    }

    public function getId(): string
    {
        return $this->id->toRfc4122();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setTitle(string $title): void
    {
        AssertService::maxLength($title, 200);
        $this->title = $title;
    }

    public function setDescription(string $description): void
    {
        AssertService::maxLength($description, 2000);
        $this->description = $description;
    }
}
