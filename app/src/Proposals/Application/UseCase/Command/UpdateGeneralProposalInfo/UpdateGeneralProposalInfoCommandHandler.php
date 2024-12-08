<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\UpdateGeneralProposalInfo;


use App\Proposals\Application\DTO\GeneralProposalInfo\GeneralProposalInfoDTOTransformer;
use App\Proposals\Application\DTO\GeneralProposalInfoItem\GeneralProposalInfoItemDTO;
use App\Proposals\Application\Service\AccessControl\GeneralProposalInfoAccessControl;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemApplicationMethod;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemCorrosiveCategory;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemDurability;
use App\Proposals\Domain\Aggregate\Proposal\CoatingSystemSurfaceTreatment;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfoItem;
use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfoUnit;
use App\Proposals\Domain\Factory\GeneralProposalInfoItemFactory;
use App\Proposals\Domain\Repository\GeneralProposalInfoItemRepositoryInterface;
use App\Proposals\Domain\Repository\GeneralProposalInfoRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\AssertService;

readonly class UpdateGeneralProposalInfoCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private GeneralProposalInfoRepositoryInterface     $generalProposalInfoRepository,
        private GeneralProposalInfoItemFactory             $generalProposalInfoItemFactory,
        private GeneralProposalInfoAccessControl           $generalProposalInfoAccessControl,
        private GeneralProposalInfoItemRepositoryInterface $generalProposalInfoItemRepository,
        private GeneralProposalInfoDTOTransformer          $generalProposalInfoDTOTransformer,
    )
    {
    }

    public function __invoke(UpdateGeneralProposalInfoCommand $command): UpdateGeneralProposalInfoCommandResult
    {
        AssertService::true(
            $this->generalProposalInfoAccessControl->canUpdateGeneralProposalInfo(
                $command->generalProposalInfoDTO->ownerId,
                $command->proposalInfoId
            ),
            'Запрещено.'
        );
        $generalProposalInfo = $this->generalProposalInfoRepository->findOneById($command->proposalInfoId);
        AssertService::notNull($generalProposalInfo, 'Форма не найдена.');

        $generalProposalInfo->setDescription($command->generalProposalInfoDTO->description);
        $generalProposalInfo->setBasis($command->generalProposalInfoDTO->basis);
        $generalProposalInfo->setUpdatedAt(new \DateTimeImmutable());
        $generalProposalInfo->setUnit(GeneralProposalInfoUnit::from($command->generalProposalInfoDTO->unit));
        $generalProposalInfo->setProjectTitle($command->generalProposalInfoDTO->projectTitle);
        $generalProposalInfo->setProjectArea($command->generalProposalInfoDTO->projectArea);
        $generalProposalInfo->setProjectStructureDescription($command->generalProposalInfoDTO->projectStructureDescription);
        $generalProposalInfo->setLoss($command->generalProposalInfoDTO->loss);
        $generalProposalInfo->setDurability(CoatingSystemDurability::from($command->generalProposalInfoDTO->durability));
        $generalProposalInfo->setCategory(CoatingSystemCorrosiveCategory::from($command->generalProposalInfoDTO->category));
        $generalProposalInfo->setTreatment(CoatingSystemSurfaceTreatment::from($command->generalProposalInfoDTO->treatment));
        $generalProposalInfo->setMethod(CoatingSystemApplicationMethod::from($command->generalProposalInfoDTO->method));

        $generalProposalInfo->getCoats()->clear();
        foreach ($command->generalProposalInfoDTO->coats as $coat) {
            if ($coat->id) {
                $exist = $this->generalProposalInfoItemRepository->findOneById($coat->id);
                AssertService::notNull($exist, 'Элемент не найден.');
                $item = $this->updateGeneralProposalInfoItem($exist, $coat);
            } else {
                $item = $this->generalProposalInfoItemFactory->create(
                    $coat->coatId,
                    $coat->coatNumber,
                    $coat->coatPrice,
                    $coat->coatDft,
                    $coat->coatColor,
                    $coat->thinnerPrice,
                    $coat->thinnerConsumption,
                    $generalProposalInfo,
                    $coat->loss,
                );
            }
            $generalProposalInfo->addItem($item);
        }
        $this->generalProposalInfoRepository->add($generalProposalInfo);
        $result = $command->returnDtoInResult ? $this->generalProposalInfoDTOTransformer->fromEntity($generalProposalInfo) : null;

        return new UpdateGeneralProposalInfoCommandResult($result);
    }

    private function updateGeneralProposalInfoItem(GeneralProposalInfoItem $item, GeneralProposalInfoItemDTO $itemDTO): GeneralProposalInfoItem
    {
        $item->setLoss($itemDTO->loss);
        $item->setCoatNumber($itemDTO->coatNumber);
        $item->setCoatPrice($itemDTO->coatPrice);
        $item->setCoatDft($itemDTO->coatDft);
        $item->setCoatColor($itemDTO->coatColor);
        $item->setThinnerPrice($itemDTO->thinnerPrice);
        $item->setThinnerConsumption($itemDTO->thinnerConsumption);
        $item->setCoatId($itemDTO->coatId);

        return $item;
    }
}
