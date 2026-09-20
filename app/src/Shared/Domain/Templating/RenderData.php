<?php

declare(strict_types=1);

namespace App\Shared\Domain\Templating;

use App\Shared\Infrastructure\Exception\AppException;

/**
 * Плоский конфиг «имя переменной → значение». Ключ — уникальная строка (латиница snake_case),
 * значение — TemplateValue (TextValue/ImageValue). Presence-driven: отсутствие ключа = «данных нет».
 */
final readonly class RenderData
{
    /** @var array<string, TemplateValue> */
    private array $values;

    /**
     * @param array<string, TemplateValue> $values
     */
    public function __construct(array $values)
    {
        foreach ($values as $name => $value) {
            if (!$value instanceof TemplateValue) {
                throw new AppException(sprintf('Значение «%s» должно быть TemplateValue.', (string) $name));
            }
        }

        $this->values = $values;
    }

    /**
     * @return list<string>
     */
    public function variableNames(): array
    {
        return array_map(strval(...), array_keys($this->values));
    }

    /**
     * Имена значений-групп (RepeatValue) — для повторяемых строк таблицы в драйвере.
     *
     * @return list<string>
     */
    public function repeatGroupNames(): array
    {
        $names = [];
        foreach ($this->values as $name => $value) {
            if ($value instanceof RepeatValue) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    public function get(string $name): ?TemplateValue
    {
        return $this->values[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->values[$name]);
    }
}
