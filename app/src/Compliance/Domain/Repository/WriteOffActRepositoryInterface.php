<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

use App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffAct;
use App\Shared\Domain\Repository\PaginationResult;

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

    /**
     * Подписанные акты требования, содержащие строки указанного профиля — read-path личной истории/карточки
     * человека (строки других профилей того же кросс-персон акта вызывающий отфильтрует по {@see \App\Compliance\Domain\Aggregate\WriteOffAct\WriteOffActLine::profileId}).
     *
     * @return list<WriteOffAct>
     */
    public function findSignedForProfileRequirement(string $profileId, string $requirementId): array;

    /** Единый поиск/список актов списания через фильтр с пагинацией (кросс-организационный, для админа). */
    public function findByFilter(WriteOffActFilter $filter): PaginationResult;

    /** Следующий номер акта В ПРЕДЕЛАХ ОРГАНИЗАЦИИ (свой ряд 1,2,3… у каждой). Потокобезопасно, атомарная выдача. */
    public function nextActNumber(string $organizationId): int;
}
