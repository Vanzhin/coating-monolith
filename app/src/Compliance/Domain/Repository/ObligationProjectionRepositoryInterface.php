<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\PaginationResult;

/**
 * Read-model проекции обязанностей (`compliance_obligation_projection`) — материализованная строка на
 * (profileId, requirementId, itemId) для дешёвых запросов мониторинга/дашборда. Единственный писатель —
 * проектор, через {@see self::upsertMany} (DBAL, минуя ORM); читает — {@see self::findByFilter}/
 * {@see self::findForProfiles} (DQL на read-only сущность).
 */
interface ObligationProjectionRepositoryInterface
{
    /** Единый поиск/список строк проекции через фильтр с пагинацией. */
    public function findByFilter(ObligationProjectionFilter $filter): PaginationResult;

    /**
     * Все строки проекции указанных людей, без пагинации — read-path дашборда (нужен весь состав, не страница).
     *
     * @return list<\App\Compliance\Infrastructure\Database\ReadModel\ObligationProjection>
     */
    public function findForProfiles(StringCollection $profileIds): array;

    /**
     * Создать/обновить строки проекции (upsert по уникальному ключу profileId+requirementId+itemId).
     * Форма строки — ассоциативный массив, ключи = имена колонок таблицы `compliance_obligation_projection`
     * (snake_case, значения — готовые к записи скаляры: даты как `Y-m-d H:i:s`/null, а не `DateTimeImmutable`).
     *
     * @param iterable<array<string, mixed>> $rows
     */
    public function upsertMany(iterable $rows): void;

    /**
     * Orphan-чистка: оставить по паре (profileId, requirementId) только переданные itemId, остальные строки
     * удалить. Нужна проектору после пересборки нормы требования — позиции, которых больше нет в норме и по
     * которым не осталось фактов, не должны фантомом висеть в проекции.
     */
    public function deleteObsoleteForProfileRequirement(string $profileId, string $requirementId, StringCollection $keepItemIds): void;

    /** Удалить все строки проекции человека (напр. при удалении его учёта). */
    public function deleteForProfile(string $profileId): void;
}
