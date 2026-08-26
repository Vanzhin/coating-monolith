<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\CoatingSystem;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use Symfony\Component\Uid\Uuid;

interface CoatingSystemRepositoryInterface
{
    public function save(CoatingSystem $system): void;

    public function remove(CoatingSystem $system): void;

    public function findById(Uuid $id): ?CoatingSystem;

    public function countUsingSurfaceTreatment(string $treatmentId): int;

    /** @return list<CoatingSystem> */
    public function findByLayerCoatingId(string $coatingId): array;

    /**
     * Системы (id+title), содержащие указанные покрытия слоями. Ключ — coatingId. Батчем, без N+1 —
     * для блока «входит в системы» в превью покрытия.
     *
     * @return array<string, list<array{id: string, title: string}>>
     */
    public function findSystemTitlesByCoatingIds(StringCollection $coatingIds): array;

    /** @return list<CoatingSystem> */
    public function findAll(): array;

    /**
     * Массовая выгрузка систем по id, порядок соответствует $ids.
     * Отсутствующие id молча пропускаются.
     *
     * @return list<CoatingSystem>
     */
    public function findByIds(StringCollection $ids): array;
}
