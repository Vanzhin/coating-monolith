<?php

declare(strict_types=1);

namespace App\Coatings\Application\Service;

use App\Coatings\Domain\Aggregate\Coating\CoatingTag;
use App\Coatings\Domain\Repository\CoatingTagRepositoryInterface;

/**
 * Builds a JSON array of general-type tags from either:
 *   - an array of CoatingTagDTO objects (from DTO transformer), or
 *   - a raw POST array with only 'id' keys (after validation error — title/type absent).
 *
 * In the raw-POST case each id is hydrated via the repository so that
 * title+type are available for filtering by TYPE_GENERAL.
 */
class GeneralTagsJsonHydrator
{
    public function __construct(
        private readonly CoatingTagRepositoryInterface $coatingTagRepository,
    ) {
    }

    /**
     * @param array<mixed> $tags coatingTagDTO objects or raw arrays with at least 'id'
     */
    public function hydrateAsJson(array $tags): string
    {
        $result = [];

        foreach ($tags as $t) {
            $id = is_object($t) ? $t->id : ($t['id'] ?? null);
            $title = is_object($t) ? $t->title : ($t['title'] ?? null);
            $type = is_object($t) ? $t->type : ($t['type'] ?? null);

            if (null === $id) {
                continue;
            }

            // Raw POST: only id present — hydrate from repository.
            if (null === $title || null === $type) {
                $entity = $this->coatingTagRepository->findOneById($id);
                if (null === $entity) {
                    continue;
                }
                $title = $entity->getTitle();
                $type = $entity->getType();
            }

            if (CoatingTag::TYPE_GENERAL !== $type) {
                continue;
            }

            $result[] = ['id' => $id, 'title' => $title];
        }

        return json_encode($result, JSON_UNESCAPED_UNICODE);
    }
}
