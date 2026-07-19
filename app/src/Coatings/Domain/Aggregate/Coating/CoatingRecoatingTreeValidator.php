<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Coating;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class CoatingRecoatingTreeValidator
{
    /**
     * Валидирует дерево интервалов перекрытия на соответствие строгому контракту ЛКМ.
     * Защищает от невалидных веток, поддерживая как плоские (80%), так и разветвленные (20%) деревья.
     */
    public function validate(RecoatingIntervalTree $tree): void
    {
        // 1. Проверяем корень. Он всегда должен иметь ключ 'default'
        if ('default' !== $tree->key) {
            throw new AppException("Корневой узел дерева интервалов должен иметь ключ 'default'.");
        }

        $rootChildren = $tree->getChildren();

        // Если детей у корня нет — это базовый плоский случай (80% ваших данных). Валидация пройдена!
        if (empty($rootChildren)) {
            return;
        }

        // Получаем списки эталонных значений из ваших Enum
        $allowedEnvironments = array_map(static fn (EnvironmentType $env) => $env->value, EnvironmentType::cases());
        $allowedBases = array_map(static fn (CoatingBase $base) => mb_strtolower($base->value, 'UTF-8'), CoatingBase::cases());

        // 2. Если дети есть, проверяем 1-й уровень (Среды эксплуатации)
        foreach ($rootChildren as $envKey => $envNode) {
            if (!in_array($envKey, $allowedEnvironments, true)) {
                throw new AppException(sprintf("Невалидный тип среды '%s' в дереве интервалов. Разрешены только: %s", $envKey, implode(', ', $allowedEnvironments)));
            }

            // Проверяем подкатегории внутри среды (2-й уровень — Основы последующего ЛКМ)
            $envChildren = $envNode->getChildren();

            if (empty($envChildren)) {
                continue;
            }

            foreach ($envChildren as $baseKey => $baseNode) {
                // 3. Проверяем ключ основы через эталонный список из Enum CoatingBase
                if (!in_array($baseKey, $allowedBases, true)) {
                    throw new AppException(sprintf("Невалидный тип последующего покрытия '%s' для среды '%s'. Разрешены только: %s", $baseKey, $envKey, implode(', ', $allowedBases)));
                }

                // 4. Проверяем 3-й уровень (Лист основы должен быть финальным и не иметь детей)
                if (!empty($baseNode->getChildren())) {
                    throw new AppException(sprintf("Узел основы ЛКМ '%s -> %s' является финальным и не может содержать вложенных элементов.", $envKey, $baseKey));
                }
            }
        }
    }
}
