<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\GetIssuePage;

/** Одно поле схемы журнала инструктажа — проекция {@see \App\Compliance\Domain\Type\FieldSpec} для формы. */
final class IssueInstructionFieldDTO
{
    public string $key;
    public string $label;
    public string $kind;
    public bool $required;
    /** @var list<string> */
    public array $options;
}
