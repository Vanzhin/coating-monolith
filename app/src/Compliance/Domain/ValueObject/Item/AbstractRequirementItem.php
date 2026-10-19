<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject\Item;

use App\Compliance\Domain\ValueObject\Cadence;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

/**
 * Общие поля позиции и их валидация. Конкретный тип (материальный/нематериальный) добавляет свои поля
 * и реализует {@see RequirementItemInterface::type()}.
 */
abstract readonly class AbstractRequirementItem implements RequirementItemInterface
{
    /** Предел длины наименования — проекция (`compliance_obligation_projection.label`) должна его вмещать, иначе пересборка откатится. */
    public const MAX_LABEL_LENGTH = 1000;

    private string $id;
    private string $label;
    private string $basis;

    public function __construct(string $id, string $label, private Cadence $cadence, string $basis, private bool $removed = false)
    {
        $id = trim($id);
        if ('' === $id) {
            throw new AppException('Внутренняя ошибка: у позиции требования нет идентификатора.');
        }
        $this->id = $id;

        $label = trim($label);
        if ('' === $label) {
            throw new AppException('Укажите наименование позиции.');
        }
        if (mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new AppException(sprintf('Наименование позиции слишком длинное (максимум %d символов).', self::MAX_LABEL_LENGTH));
        }
        $this->label = $label;

        $basis = trim($basis);
        if ('' === $basis) {
            throw new AppException(sprintf('Укажите основание (по какой причине) для «%s».', $label));
        }
        $this->basis = $basis;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function cadence(): Cadence
    {
        return $this->cadence;
    }

    public function basis(): string
    {
        return $this->basis;
    }

    public function isRemoved(): bool
    {
        return $this->removed;
    }

    /**
     * Общая часть сериализации; конкретный тип дополняет своими полями.
     *
     * @return array{id: string, label: string, cadence: array{kind: string, number: int|null}, basis: string, removed?: true}
     */
    protected function baseArray(): array
    {
        $base = [
            'id' => $this->id,
            'label' => $this->label,
            'cadence' => $this->cadence->jsonSerialize(),
            'basis' => $this->basis,
        ];
        if ($this->removed) {
            $base['removed'] = true;
        }

        return $base;
    }

    /**
     * Back-compat: легаси jsonb-строки до бэкфилла могут не содержать id — генерируем, чтобы не падать.
     * Основной путь (билдер) всегда передаёт id явно.
     *
     * @param array<string, mixed> $data
     */
    protected static function readId(array $data): string
    {
        $id = trim((string) ($data['id'] ?? ''));

        return '' !== $id ? $id : Uuid::v7()->toRfc4122();
    }

    /** @param array<string, mixed> $data */
    protected static function readRemoved(array $data): bool
    {
        return true === ($data['removed'] ?? false);
    }
}
