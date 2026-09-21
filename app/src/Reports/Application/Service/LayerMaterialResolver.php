<?php

declare(strict_types=1);

namespace App\Reports\Application\Service;

use App\Coatings\Domain\Repository\CoatingRepositoryInterface;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Block\BlockRegistry;
use App\Reports\Domain\Block\FieldType;

/**
 * Материал слоя приходит с формы как id покрытия; здесь резолвим его в снимок {id,title} из каталога
 * (авторитетно на бэке, как ссылки шапки). Найдено — заменяем; иначе оставляем как есть (валидатор
 * отобьёт не-ссылку). Проходим по Layers-полям блоков типа.
 */
final readonly class LayerMaterialResolver
{
    public function __construct(
        private BlockRegistry $registry,
        private CoatingRepositoryInterface $coatings,
    ) {
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    public function resolve(ReportType $type, array $content): array
    {
        foreach ($type->blockKeys() as $blockKey) {
            foreach ($this->registry->get($blockKey)->fields() as $field) {
                if (FieldType::Layers !== $field->type) {
                    continue;
                }
                $layers = $content[$blockKey->value][$field->key] ?? null;
                if (!is_array($layers)) {
                    continue;
                }
                foreach ($layers as $i => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    foreach ($field->itemFields as $sub) {
                        if (FieldType::CoatingRef !== $sub->type) {
                            continue;
                        }
                        $value = $row[$sub->key] ?? null;
                        if (is_string($value) && '' !== $value) {
                            $coating = $this->coatings->findOneById($value);
                            if (null !== $coating) {
                                $content[$blockKey->value][$field->key][$i][$sub->key] = ['id' => $coating->getId(), 'title' => $coating->getTitle()];
                            }
                        }
                    }
                }
            }
        }

        return $content;
    }
}
