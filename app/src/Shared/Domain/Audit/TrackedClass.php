<?php
declare(strict_types=1);
namespace App\Shared\Domain\Audit;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Конфиг одного трекаемого класса: FQCN + карта отслеживаемых полей на человеческие подписи.
 * Наличие строки = класс аудируется. Пустой набор полей запрещён (тогда удаляй строку).
 */
class TrackedClass
{
    /** @var array<string, string> */
    private array $fields;

    /** @param array<string, string> $fields поле → подпись */
    public function __construct(
        private readonly string $id,
        private readonly string $entityClass,
        array $fields,
    ) {
        if ('' === $entityClass) {
            throw new AppException('Класс для аудита не задан.');
        }
        $this->fields = $this->normalizeFields($fields);
    }

    public function id(): string { return $this->id; }
    public function entityClass(): string { return $this->entityClass; }

    /** @return array<string, string> */
    public function fields(): array { return $this->fields; }

    /** @param array<string, string> $fields */
    public function retrack(array $fields): void { $this->fields = $this->normalizeFields($fields); }

    /**
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    private function normalizeFields(array $fields): array
    {
        if ([] === $fields) {
            throw new AppException('Нужно выбрать хотя бы одно поле для аудита.');
        }
        $out = [];
        foreach ($fields as $name => $label) {
            $out[(string) $name] = '' === (string) $label ? (string) $name : (string) $label; // пустая подпись → имя поля
        }

        return $out;
    }
}
