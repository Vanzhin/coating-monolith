<?php
declare(strict_types=1);


namespace App\Coatings\Application\DTO\CoatingTags;


use App\Coatings\Domain\Aggregate\Coating\CoatingTag;

class CoatingTagDTOTransformer
{
    public function fromEntity(CoatingTag $entity): object
    {

        $dto = new CoatingTagDTO();
        $dto->id = $entity->getId();
        $dto->title = $entity->getTitle();
        $dto->type = $entity->getType();

        return $dto;
    }

    /**
     * @param array<CoatingTag> $coatingTags
     *
     * @return array<CoatingTagDTO>
     */
    public function fromEntityList(array $coatingTags): array
    {
        $coatingTagDTOs = [];
        foreach ($coatingTags as $coatingTag) {
            $coatingTagDTOs[] = $this->fromEntity($coatingTag);
        }

        return $coatingTagDTOs;
    }

}