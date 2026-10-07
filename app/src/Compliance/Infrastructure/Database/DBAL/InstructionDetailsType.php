<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Database\DBAL;

use App\Compliance\Domain\ValueObject\Instruction\InstructionDetails;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

final class InstructionDetailsType extends AbstractJsonObjectType
{
    public const NAME = 'compliance_instruction_details';

    protected function valueClass(): string
    {
        return InstructionDetails::class;
    }

    /**
     * @param array<string, mixed> $raw
     */
    protected function hydrate(array $raw): InstructionDetails
    {
        return InstructionDetails::fromArray($raw);
    }
}
