<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Service;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;
use Webmozart\Assert\Assert;

readonly class CoatingTagFetcher
{
    public function __construct(private CoatingTagRepositoryInterface $coatingTagRepository)
    {
    }

    public function getRequiredTag(string $id): CoatingTag
    {
        $coatingTag = $this->coatingTagRepository->findOneById($id);
        Assert::notNull($coatingTag, 'Coating tag not found.');

        return $coatingTag;
    }

}