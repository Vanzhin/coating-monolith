<?php

declare(strict_types=1);

namespace App\Reports\Application\Service;

use App\Coatings\Application\UseCase\Query\GetCoatingsByIds\GetCoatingsByIdsQuery;
use App\Coatings\Application\UseCase\Query\GetCoatingsByIds\GetCoatingsByIdsQueryResult;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Block\BlockRegistry;
use App\Reports\Domain\Block\FieldType;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

/**
 * Материал слоя приходит с формы как id покрытия; здесь резолвим его в снимок {id,title} из каталога
 * (авторитетно на бэке, как ссылки шапки). Найдено — заменяем; иначе оставляем как есть (валидатор
 * отобьёт не-ссылку). Проходим по Layers-полям блоков типа.
 *
 * Каталог покрытий — чужой контекст: тянем через опубликованный query Coatings (DTO), а не репозиторий
 * домена. Один батч-запрос на все слои (без N+1).
 */
final readonly class LayerMaterialResolver
{
    public function __construct(
        private BlockRegistry $registry,
        private QueryBusInterface $queryBus,
    ) {
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    public function resolve(ReportType $type, array $content): array
    {
        // 1) собрать все id покрытий из Layers-полей + позиции для замены
        $ids = [];
        $refs = [];
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
                            $ids[$value] = true;
                            $refs[] = [$blockKey->value, $field->key, $i, $sub->key, $value];
                        }
                    }
                }
            }
        }

        if ([] === $refs) {
            return $content;
        }

        // 2) один запрос в каталог → снимки {id,title}
        $result = $this->queryBus->execute(new GetCoatingsByIdsQuery(new StringCollection(...array_keys($ids))));
        $titleById = [];
        if ($result instanceof GetCoatingsByIdsQueryResult) {
            foreach ($result->coatings as $coating) {
                $titleById[$coating->id] = $coating->title;
            }
        }

        // 3) заменить найденные ссылки на снимок (не найдено → оставляем id, валидатор отобьёт)
        foreach ($refs as [$blockKeyValue, $fieldKey, $i, $subKey, $id]) {
            if (isset($titleById[$id])) {
                $content[$blockKeyValue][$fieldKey][$i][$subKey] = ['id' => $id, 'title' => $titleById[$id]];
            }
        }

        return $content;
    }
}
