<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetIssuePage;

/** Группа полей инструктажа (напр. «Теоретическая часть» / «Практическая часть») — по {@see \App\Compliance\Domain\Type\FieldSpec::$group}. */
final class IssueInstructionGroupDTO
{
    public ?string $label = null;
    /** @var list<IssueInstructionFieldDTO> */
    public array $fields = [];
}
