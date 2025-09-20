<?php

declare(strict_types=1);

namespace App\Users\Domain\Entity;

use Ramsey\Uuid\UuidInterface;

class Channel
{
    private bool $isVerified = false;
    private ?\DateTimeImmutable $verifiedAt = null;
    private ?Token $token = null;

    public function __construct(
        private UuidInterface $id,
        private ChannelType $type,
        private string $value,
        private User $owner
    ) {
    }

    // Геттеры
    public function getId(): UuidInterface
    {
        return $this->id;
    }

    public function getType(): ChannelType
    {
        return $this->type;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function getVerifiedAt(): ?\DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function getVerificationToken(): ?Token
    {
        return $this->token;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    /**
     * Основной метод верификации
     */
    public function verify(string $code): bool
    {
        if ($this->token === null || !$this->token->isValid()) {
            return false;
        }

        if (!$this->token->equals($code)) {
            return false;
        }

        $this->markAsVerified();
        return true;
    }

    /**
     * Установка токена верификации
     */
    public function setVerificationToken(Token $token): void
    {
        $this->token = $token;
        $this->isVerified = false;
        $this->verifiedAt = null;
    }

    /**
     * Очистка токена верификации
     */
    public function clearVerificationToken(): void
    {
        $this->token = null;
    }

    /**
     * Проверка возможности верификации
     */
    public function isValidForVerification(): bool
    {
        return $this->token !== null &&
            $this->token->isValid();
    }

    /**
     * Внутренний метод пометки как верифицированного
     */
    private function markAsVerified(): void
    {
        $this->isVerified = true;
        $this->verifiedAt = new \DateTimeImmutable();
        $this->token = null;
    }
}