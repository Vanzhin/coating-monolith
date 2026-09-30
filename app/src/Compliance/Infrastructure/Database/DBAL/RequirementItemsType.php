<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Database\DBAL;

use App\Compliance\Domain\Type\ComplianceType;
use App\Compliance\Domain\ValueObject\Item\RequirementItemInterface;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\JsonType;

/**
 * DBAL-тип для позиций требования (list<RequirementItemInterface>), хранимых в jsonb.
 *
 * Дискриминатор типа позиции — забота хранения, а не домена: `jsonSerialize()` позиции остаётся чистым
 * (label/cadence/basis/quantity, без type), а этот тип на записи добавляет `type` (из `$item->type()`),
 * на чтении по нему выбирает класс ({@see ComplianceType::makeItem()}). Так полиморфный список
 * восстанавливается, а доменная VO не тащит персист-специфику.
 */
final class RequirementItemsType extends JsonType
{
    public const NAME = 'compliance_requirement_items';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'JSONB';
    }

    /**
     * @param list<RequirementItemInterface>|null $value
     */
    public function convertToDatabaseValue($value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }
        if (!is_array($value)) {
            throw new \InvalidArgumentException('Ожидался list<RequirementItemInterface>.');
        }

        $rows = array_map(
            static fn (RequirementItemInterface $item): array => ['type' => $item->type()->value, ...$item->jsonSerialize()],
            array_values($value),
        );

        return parent::convertToDatabaseValue($rows, $platform);
    }

    /**
     * @return list<RequirementItemInterface>
     */
    public function convertToPHPValue($value, AbstractPlatform $platform): array
    {
        if (null === $value) {
            return [];
        }
        $raw = parent::convertToPHPValue($value, $platform);
        if (!is_array($raw)) {
            throw new \UnexpectedValueException('Ожидался JSON-массив позиций требования.');
        }

        return array_map(
            static fn (array $row): RequirementItemInterface => ComplianceType::from((string) $row['type'])->makeItem($row),
            array_values($raw),
        );
    }
}
