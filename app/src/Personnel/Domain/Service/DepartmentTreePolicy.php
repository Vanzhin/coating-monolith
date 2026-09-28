<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Service;

use App\Personnel\Domain\Aggregate\Department\Department;
use App\Personnel\Domain\Repository\DepartmentRepositoryInterface;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Инварианты дерева отделов: родитель обязан быть из той же компании, дерево не может
 * содержать циклов (узел не может стать подчинённым собственному потомку или себе).
 */
final readonly class DepartmentTreePolicy
{
    public function __construct(private DepartmentRepositoryInterface $repository)
    {
    }

    public function assertParentValid(Department $node, string $parentId): void
    {
        if ($parentId === $node->getId()) {
            throw new AppException('Нельзя сделать отдел подчинённым самому себе.');
        }

        $parent = $this->repository->findOneById($parentId);
        if (null === $parent) {
            throw new AppException('Родительский отдел не найден.');
        }

        if ($parent->getCompanyId() !== $node->getCompanyId()) {
            throw new AppException('Родительский отдел из другой компании.');
        }

        // Поднимаемся от кандидата в родители вверх по дереву: если встретим сам узел —
        // значит кандидат лежит в поддереве узла, и назначение создаст цикл. Подъём делегирован
        // репозиторию (findAncestors уже защищён от зацикливания на испорченных данных —
        // напр. цикле A→B→C→A, не проходящем через сам $node, — своим visited-набором).
        $parentAncestorIds = array_map(
            static fn (Department $ancestor): string => $ancestor->getId(),
            $this->repository->findAncestors($parent->getId()),
        );
        if (\in_array($node->getId(), $parentAncestorIds, true)) {
            throw new AppException('Нельзя сделать отдел подчинённым своему потомку (цикл).');
        }
    }
}
