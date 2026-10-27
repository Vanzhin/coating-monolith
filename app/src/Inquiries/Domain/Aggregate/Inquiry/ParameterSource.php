<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Aggregate\Inquiry;

/**
 * Провенанс распознанных параметров: стратегия + непрозрачные детали. Для Model — модель/версия
 * промпта/токены/время; для Cache — sourceInquiryId/similarity; для Manual — пусто или заметка.
 * Домен детали не трактует. Хранится jsonb.
 */
final readonly class ParameterSource implements \JsonSerializable
{
    /** @param array<string, mixed> $details */
    public function __construct(public ParameterStrategy $strategy, public array $details = [])
    {
    }

    /** @return array{strategy: string, details: array<string, mixed>} */
    public function jsonSerialize(): array
    {
        return ['strategy' => $this->strategy->value, 'details' => $this->details];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $raw = $data['strategy'] ?? null;
        $strategy = is_string($raw) ? (ParameterStrategy::tryFrom($raw) ?? ParameterStrategy::Model) : ParameterStrategy::Model;
        $details = isset($data['details']) && is_array($data['details']) ? $data['details'] : [];

        return new self($strategy, $details);
    }
}
