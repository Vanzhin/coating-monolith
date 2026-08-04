<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Service;

use App\Coatings\Domain\Aggregate\Tag\Tag;
use App\Coatings\Domain\Repository\TagRepositoryInterface;
use Webmozart\Assert\Assert;

readonly class TagFetcher
{
    public function __construct(private TagRepositoryInterface $coatingTagRepository)
    {
    }

    public function getRequiredTag(string $id): Tag
    {
        $coatingTag = $this->coatingTagRepository->findOneById($id);
        Assert::notNull($coatingTag, 'Coating tag not found.');

        return $coatingTag;
    }
}
