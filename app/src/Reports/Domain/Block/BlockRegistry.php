<?php

declare(strict_types=1);

namespace App\Reports\Domain\Block;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Реестр блоков: собирает все BlockDefinition (tagged-iterator) в карту по BlockKey.
 * Источник схемы для валидации content и проекции в документ.
 */
final class BlockRegistry
{
    /** @var array<string, BlockDefinition> */
    private array $byKey = [];

    /**
     * @param iterable<BlockDefinition> $definitions
     */
    public function __construct(iterable $definitions)
    {
        foreach ($definitions as $definition) {
            $this->byKey[$definition->key()->value] = $definition;
        }
    }

    public function get(BlockKey $key): BlockDefinition
    {
        return $this->byKey[$key->value]
            ?? throw new AppException(sprintf('Блок «%s» не зарегистрирован.', $key->value));
    }

    public function has(BlockKey $key): bool
    {
        return isset($this->byKey[$key->value]);
    }
}
