<?php

declare(strict_types=1);


namespace App\Coatings\Application\UseCase\Command\UpdateCoating;

use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Coatings\Domain\Repository\ManufacturerRepositoryInterface;
use App\Coatings\Domain\Service\CoatingTagFetcher;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class UpdateCoatingCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingRepositoryInterface      $coatingRepository,
        private ManufacturerRepositoryInterface $manufacturerRepository,
        private CoatingTagFetcher               $coatingTagFetcher
    )
    {
    }

    public function __invoke(UpdateCoatingCommand $command): UpdateCoatingCommandResult
    {
        $coating = $this->coatingRepository->findOneById($command->coatingId);

        if ($manufacturer = $command->coatingDTO->manufacturer) {
            $coating->setManufacturer($this->manufacturerRepository->findOneById($manufacturer->id));
        }
        if ($command->coatingDTO->title) {
            $coating->setTitle($command->coatingDTO->title);
        }
        if ($command->coatingDTO->description) {
            $coating->setDescription($command->coatingDTO->description);
        }
        if ($command->coatingDTO->dryToTouch) {
            $coating->setDryToTouch($command->coatingDTO->dryToTouch);
        }
        if ($command->coatingDTO->volumeSolid) {
            $coating->setVolumeSolid($command->coatingDTO->volumeSolid);
        }
        if ($command->coatingDTO->massDensity) {
            $coating->setMassDensity($command->coatingDTO->massDensity);
        }
        if ($command->coatingDTO->tdsDft) {
            $coating->setTdsDft($command->coatingDTO->tdsDft);
        }
        if ($command->coatingDTO->minDft) {
            $coating->setMinDft($command->coatingDTO->minDft);
        }
        if ($command->coatingDTO->maxDft) {
            $coating->setMaxDft($command->coatingDTO->maxDft);
        }
        if ($command->coatingDTO->applicationMinTemp) {
            $coating->setApplicationMinTemp($command->coatingDTO->applicationMinTemp);
        }
        if ($command->coatingDTO->minRecoatingInterval) {
            $coating->setMinRecoatingInterval($command->coatingDTO->minRecoatingInterval);
        }
        if ($command->coatingDTO->maxRecoatingInterval) {
            $coating->setMaxRecoatingInterval($command->coatingDTO->maxRecoatingInterval);
        }
        if ($command->coatingDTO->fullCure) {
            $coating->setFullCure($command->coatingDTO->fullCure);
        }
        if ($command->coatingDTO->pack) {
            $coating->setPack($command->coatingDTO->pack);
        }
        if ($command->coatingDTO->tags) {
            $coating->getTags()->clear();
            foreach ($command->coatingDTO->tags as $coatingTagDTO) {
                $coating->addTag($this->coatingTagFetcher->getRequiredTag($coatingTagDTO->id));
            }
        }
        $this->coatingRepository->add($coating);

        return new UpdateCoatingCommandResult();
    }
}
