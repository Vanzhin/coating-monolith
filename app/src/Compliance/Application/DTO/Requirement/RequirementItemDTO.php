<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Requirement;

/**
 * Позиция требования для формы/показа. type — вид ЭТОЙ позиции (material/periodic): у «Выдачи» они могут
 * отличаться от типа требования (инвентарь+расходник в одной карточке), у «Периодической» всегда совпадает.
 * cadenceNumber/amount/unit — для полей ввода; *Label — для показа. Количество заполнено у обоих видов
 * позиции (requiresQuantity()). id — стабильный идентификатор позиции, нужен форме для round-trip правки.
 * removed — позиция мягко убрана из нормы ({@see \App\Compliance\Domain\ValueObject\Item\AbstractRequirementItem::isRemoved()}):
 * редактор требования рисует её greyed+lock с «Восстановить» (единственное место восстановления — норма);
 * списочная страница требований её игнорирует (см. {@see \App\Compliance\Application\UseCase\Query\ListRequirements\ListRequirementsQueryHandler}).
 */
final class RequirementItemDTO
{
    public string $id;
    public string $label;
    public string $type;
    public string $cadenceKind;
    public ?int $cadenceNumber = null;
    public ?string $cadenceUnit = null;
    public string $cadenceLabel;
    public ?float $amount = null;
    public ?string $unit = null;
    public string $quantityLabel = '';
    public string $basis;
    public bool $removed = false;
}
