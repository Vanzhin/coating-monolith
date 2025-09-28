<?php

declare(strict_types=1);

namespace App\Users\Domain\Entity;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class Token
{
    public function __construct(
        private string $token,
        private string $subjectId,
        private DateTimeImmutable $expiresAt,
    ) {
        if ($expiresAt <= new DateTimeImmutable()) {
            throw new \InvalidArgumentException('Expiration date must be in the future');
        }
    }

    public function getSubjectId(): string
    {
        return $this->subjectId;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isValid(): bool
    {
        return $this->expiresAt > new DateTimeImmutable();
    }

    public function equals(string $code): bool
    {
        return hash_equals($this->token, $code);
    }

    public function getRemainingTime(): \DateInterval
    {
        return $this->expiresAt->diff(new DateTimeImmutable());
    }

    public function getRemainingTimeInSeconds(): int
    {
        $now = new DateTimeImmutable();

        // Если токен уже истек, возвращаем 0
        if ($this->expiresAt <= $now) {
            return 0;
        }

        // Вычисляем разницу в секундах
        $difference = $this->expiresAt->getTimestamp() - $now->getTimestamp();

        // Возвращаем максимум 0, если по какой-то причине разница отрицательная
        return max(0, $difference);
    }

    public function __toString(): string
    {
        return $this->token;
    }

    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'subjectId' => $this->subjectId,
            'expiresAt' => $this->expiresAt->format(DateTimeInterface::ATOM),
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['token'],
            $data['subjectId'],
            new DateTimeImmutable($data['expiresAt']),
        );
    }

}