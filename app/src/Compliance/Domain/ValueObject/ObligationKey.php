<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject;

/**
 * Ключ обязанности человека — `requirementId|itemId` (itemId — стабильный id позиции, не человекочитаемый
 * label; переименование позиции не рвёт связь факта). Формат ключа — знание домена, наружу (Application/
 * Infrastructure) не отдаём — любая операция с ключом идёт через эти статики, а не ручную склейку/разбор строки.
 * Перенесено из бывшей доменной сущности-проекции (Task 7, Вариант B: агрегат норму не материализует, но ключ —
 * общий контракт между журналом фактов {@see \App\Compliance\Domain\Aggregate\ProfileCompliance\ProfileCompliance}
 * и проектором read-модели {@see \App\Compliance\Application\Service\ComplianceObligationProjector}).
 */
final readonly class ObligationKey
{
    /** `$suffix` — стабильный itemId позиции (не человекочитаемый label), регистр не нормализуем. */
    public static function keyOf(string $requirementId, string $suffix): string
    {
        return $requirementId.'|'.trim($suffix);
    }

    /** Принадлежность ключа обязанности требованию (формат ключа — знание домена, наружу не отдаём). */
    public static function keyBelongsToRequirement(string $key, string $requirementId): bool
    {
        return str_starts_with($key, $requirementId.'|');
    }

    /**
     * Обратная операция к {@see keyOf}: requirementId — префикс ключа до первого `|` (сам requirementId — Uuid,
     * символа `|` содержать не может, поэтому извлечение детерминированно и не требует перебора/проекции).
     */
    public static function requirementIdOf(string $key): string
    {
        $pos = strpos($key, '|');
        if (false === $pos) {
            throw new \InvalidArgumentException(sprintf('Некорректный ключ обязанности: "%s".', $key));
        }

        return substr($key, 0, $pos);
    }

    /**
     * Человекочитаемая часть значения: если строка — сырой ключ обязанности (requirementId-Uuid + `|` + суффикс),
     * вернуть суффикс (itemId или — у orphan-факта переименованной/убранной позиции — старый label), иначе строку
     * как есть. Нужно для отображения, где нерезолвимый ключ «запёкся» целиком: фоллбэк резолва имени и baked-снапшот
     * строки акта списания. Префикс до первого `|` — Uuid (символа `|` не содержит), поэтому детект детерминирован.
     */
    public static function humanLabelOf(string $value): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\|(.+)$/i', $value, $m)) {
            return $m[1];
        }

        return $value;
    }
}
