<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\Color\Color;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

interface ColorRepositoryInterface
{
    public function add(Color $color): void;

    public function findOneById(string $id): ?Color;

    /**
     * Поиск по паре (название, hex) — название регистронезависимо. Для контроля
     * уникальности пары при создании цвета.
     */
    public function findOneByNameAndHex(string $name, string $hex): ?Color;

    /**
     * Массовая выгрузка по id (несуществующие id молча дропаются).
     *
     * @return list<Color>
     */
    public function findByIds(StringCollection $ids): array;

    /**
     * Подсказки для typeahead: подстрочный регистронезависимый поиск по названию
     * ИЛИ по коду RAL (пользователь печатает «серый» или «RAL 7040» / «7040»).
     *
     * @return list<Color>
     */
    public function suggest(string $query, int $limit): array;
}
