<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Requirement;

/** Требование для формы/списка: id, имя, тип, покрытые должности (чипы), позиции. */
final class RequirementDTO
{
    public string $id;
    public string $name;
    public string $type;
    public string $typeLabel;
    /** Загружен ли свой шаблон документа (для формы: показать «заменить/удалить» вместо «загрузить»). */
    public bool $hasTemplate = false;
    /** @var list<PositionRefDTO> */
    public array $positions = [];
    /** @var list<RequirementItemDTO> */
    public array $items = [];
}
