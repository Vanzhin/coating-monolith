<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\Tags;

use App\Coatings\Domain\Aggregate\Tag\Tag;

class TagDTOTransformer
{
    public function fromEntity(Tag $entity): object
    {
        $dto = new TagDTO();
        $dto->id = $entity->getId();
        $dto->title = $entity->getTitle();
        $dto->type = $entity->getType();

        return $dto;
    }

    /**
     * @param array<Tag> $coatingTags
     *
     * @return array<TagDTO>
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
