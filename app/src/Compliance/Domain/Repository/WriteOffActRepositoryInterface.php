<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;

interface WriteOffActRepositoryInterface
{
    /** Открытый черновик акта для пары (требование × организация) — корзина наполнения. Lines fetch-join'ятся. */
    public function findOpenDraft(string $requirementId, string $organizationId): ?WriteOffAct;

    public function findById(string $actId): ?WriteOffAct;

    public function add(WriteOffAct $act): void;

    public function remove(WriteOffAct $act): void;

    /**
     * Черновики, содержащие строку по указанному факту выдачи — для каскада при удалении факта выдачи.
     * Подписанные акты не трогаем, в выборку не попадают.
     *
     * @return list<WriteOffAct>
     */
    public function findDraftsContainingRecord(string $recordId): array;
}
