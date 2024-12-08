<?php
declare(strict_types=1);


namespace App\Shared\Infrastructure\Twig\Components;

use App\Coatings\Application\DTO\Coatings\CoatingDTO;
use App\Proposals\Application\DTO\GeneralProposalInfoItem\GeneralProposalInfoItemDTO;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

#[AsLiveComponent]
class GeneralProposalInfoItem
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public bool $addItem = false;
    /**
     * @var GeneralProposalInfoItemDTO[]
     */
    #[LiveProp(useSerializerForHydration: true)]
    public array $itemDtoCollection = [];

    /**
     * @var CoatingDTO[]
     */
    #[LiveProp(useSerializerForHydration: true)]
    public array $coatingDtoCollection = [];

    #[PostMount]
    public function postMount(): void
    {
        if ($this->addItem) {
            $this->itemDtoCollection[] = new GeneralProposalInfoItemDTO();
        }
    }

}