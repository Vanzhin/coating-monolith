<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Department;

use App\Personnel\Application\DTO\Department\DepartmentNodeDTO;

/**
 * Разворачивает вложенное дерево отделов компании (DepartmentNodeDTO[]) в плоский список
 * опций для <select name="parentId"> — с отступом по глубине. Чистое view-преобразование
 * над уже загруженным DTO (без похода в репозиторий): не бизнес-правило, а инфраструктурное
 * удобство отображения. Один и тот же список используется в модалках «Добавить»/«Перенести»
 * на дереве (ListAction); для переноса JS модалки прячет вариант «в самого себя» по id
 * (data-bs-current-id) — это только UX-подсказка, настоящий инвариант («та же компания,
 * без циклов») проверяет DepartmentTreePolicy на бэке независимо от того, что показал список.
 */
final class DepartmentTreeOptions
{
    /**
     * @param list<DepartmentNodeDTO> $roots
     *
     * @return list<array{id: string, label: string}>
     */
    public static function flatten(array $roots): array
    {
        $options = [];
        self::walk($roots, 0, $options);

        return $options;
    }

    /**
     * @param list<DepartmentNodeDTO>                $nodes
     * @param list<array{id: string, label: string}> $options
     */
    private static function walk(array $nodes, int $depth, array &$options): void
    {
        foreach ($nodes as $node) {
            $options[] = ['id' => $node->id, 'label' => str_repeat('— ', $depth).$node->title];
            self::walk($node->children, $depth + 1, $options);
        }
    }
}
