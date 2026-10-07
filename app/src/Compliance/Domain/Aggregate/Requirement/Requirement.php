<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Aggregate\Requirement;

use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Item\RequirementItemInterface;
use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

/**
 * Требование — что положено/что нужно проходить на должности. Имеет имя (заголовок для документа-вьюхи)
 * и тип ({@see ComplianceType}), заданный при создании и неизменяемый: другой тип — это другое требование.
 * Несёт однотипные позиции: тип — источник истины, {@see supports()} принимает позицию, только если её
 * класс-тип совпадает с типом требования. Прикручено к нескольким должностям без ограничений уникальности
 * (одна должность может входить в несколько требований любого типа).
 *
 * Позиции — типизированные `RequirementItemInterface[]`, хранятся jsonb-типом `compliance_requirement_items`
 * (в json у позиции есть дискриминатор типа — так сериализуется полиморфный список; поля типа у самой
 * позиции нет, `type()` берётся из класса).
 */
class Requirement extends Aggregate
{
    private readonly Uuid $id;
    private string $name;
    private readonly ComplianceType $type;
    private StringCollection $positionIds;
    /** @var RequirementItemInterface[] */
    private array $items;
    /** Файл-шаблон документа этого требования (uuid в едином файловом реестре); null → печатается дефолтная карточка. */
    private ?string $templateFileId = null;
    private int $version = 1;

    public function __construct(
        Uuid $id,
        string $name,
        ComplianceType $type,
        StringCollection $positionIds,
        RequirementItemInterface ...$items,
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->positionIds = $positionIds;
        $this->rename($name);
        $this->items = $this->accepted($items);
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ('' === $name) {
            throw new AppException('Укажите название требования.');
        }
        $this->name = $name;
    }

    public function replacePositions(StringCollection $positionIds): void
    {
        $this->positionIds = $positionIds;
    }

    public function replaceItems(RequirementItemInterface ...$items): void
    {
        $this->items = $this->accepted($items);
    }

    /** Принимает ли требование эту позицию: её тип должен совпадать с типом требования. */
    public function supports(RequirementItemInterface $item): bool
    {
        return $item->type() === $this->type;
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): ComplianceType
    {
        return $this->type;
    }

    /** Файл-шаблон документа требования (uuid) или null — тогда печатается дефолтная карточка. */
    public function getTemplateFileId(): ?string
    {
        return $this->templateFileId;
    }

    public function setTemplateFileId(?string $templateFileId): void
    {
        $this->templateFileId = ('' === $templateFileId) ? null : $templateFileId;
    }

    public function getPositionIds(): StringCollection
    {
        return $this->positionIds;
    }

    /** @return RequirementItemInterface[] */
    public function getItems(): array
    {
        return $this->items;
    }

    public function coversPosition(string $positionId): bool
    {
        return in_array($positionId, $this->positionIds->getList(), true);
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Единственная точка входа позиций: держит однотипность через supports и запрещает дубли по наименованию.
     *
     * @param RequirementItemInterface[] $items
     *
     * @return RequirementItemInterface[]
     */
    private function accepted(array $items): array
    {
        $seen = [];
        foreach ($items as $item) {
            if (!$this->supports($item)) {
                throw new AppException(sprintf('Требование «%s» (%s) не принимает позицию «%s» (%s).', $this->name, $this->type->title(), $item->label(), $item->type()->title()));
            }

            $key = mb_strtolower($item->label());
            if (isset($seen[$key])) {
                throw new AppException(sprintf('Дублирующая позиция: «%s».', $item->label()));
            }
            $seen[$key] = true;
        }

        return array_values($items);
    }
}
