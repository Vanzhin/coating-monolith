<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Database\DBAL;

use App\Coatings\Domain\Aggregate\Coating\RecoatingIntervalTree;
use App\Shared\Infrastructure\Database\DBAL\AbstractJsonObjectType;

final class RecoatingIntervalTreeType extends AbstractJsonObjectType
{
    public const NAME = 'recoating_interval_tree';

    public function getName(): string
    {
        return self::NAME;
    }

    protected function valueClass(): string
    {
        return RecoatingIntervalTree::class;
    }

    protected function hydrate(array $raw): RecoatingIntervalTree
    {
        return RecoatingIntervalTree::fromArray($raw);
    }
}
