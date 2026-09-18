<?php

declare(strict_types=1);

namespace App\Shared\Domain\Audit;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Конфиг одного трекаемого класса: FQCN + карта отслеживаемых полей на человеческие подписи.
 * Наличие строки = класс аудируется. Пустой набор полей запрещён (тогда удаляй строку).
 *
 * Запись поля — либо старая форма (просто подпись строкой), либо новая
 * `{label, kind}` (добавляет вид поля для {@see AuditFieldKind}). Doctrine
 * гидрирует `fields` (json) НАПРЯМУЮ в свойство, мимо конструктора — обе формы
 * могут прийти из БД как есть, поэтому accessor'ы `fields()`/`kinds()` читают их
 * терпимо независимо от того, как объект оказался заполнен.
 */
class TrackedClass
{
    /** @var array<string, string|array{label?: string, kind?: string}> */
    private array $fields;

    /** @param array<string, string|array{label?: string, kind?: string}> $fields поле → подпись|{label,kind} */
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

    public function id(): string
    {
        return $this->id;
    }

    public function entityClass(): string
    {
        return $this->entityClass;
    }

    /** @return array<string, string> поле → подпись */
    public function fields(): array
    {
        $out = [];
        foreach ($this->fields as $name => $entry) {
            $out[(string) $name] = $this->labelOf((string) $name, $entry);
        }

        return $out;
    }

    /** @return array<string, string> поле → вид ({@see AuditFieldKind}::value), нет kind → scalar */
    public function kinds(): array
    {
        $out = [];
        foreach ($this->fields as $name => $entry) {
            $out[(string) $name] = $this->kindOf($entry);
        }

        return $out;
    }

    /** @param array<string, string|array{label?: string, kind?: string}> $fields */
    public function retrack(array $fields): void
    {
        $this->fields = $this->normalizeFields($fields);
    }

    /**
     * @param array<string, string|array{label?: string, kind?: string}> $fields
     *
     * @return array<string, string|array{label?: string, kind?: string}>
     */
    private function normalizeFields(array $fields): array
    {
        if ([] === $fields) {
            throw new AppException('Нужно выбрать хотя бы одно поле для аудита.');
        }
        $out = [];
        foreach ($fields as $name => $entry) {
            $out[(string) $name] = $entry; // храним как пришло — строку или {label,kind}
        }

        return $out;
    }

    /** @param string|array{label?: string, kind?: string} $entry */
    private function labelOf(string $name, string|array $entry): string
    {
        $label = is_array($entry) ? (string) ($entry['label'] ?? '') : $entry;

        return '' === $label ? $name : $label; // пустая подпись → имя поля
    }

    /** @param string|array{label?: string, kind?: string} $entry */
    private function kindOf(string|array $entry): string
    {
        if (!is_array($entry)) {
            return AuditFieldKind::Scalar->value;
        }

        return (string) ($entry['kind'] ?? AuditFieldKind::Scalar->value);
    }
}
