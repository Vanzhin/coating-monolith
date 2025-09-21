<?php

declare(strict_types=1);

namespace App\Users\Domain\Entity;

use Symfony\Component\Uid\Uuid;

class Channel
{
    private bool $isVerified = false;
    private ?\DateTimeImmutable $verifiedAt = null;

    public function __construct(
        private readonly Uuid $id,
        private ChannelType $type,
        private string $value,
        private readonly User $owner
    ) {
    }

    // Геттеры
    public function getId(): Uuid
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

    public function getOwner(): User
    {
        return $this->owner;
    }

    //todo надо бы заприватить, но тогда нужно прикручивать токен.
    public function setIsVerified(): void
    {
        $this->isVerified = true;
        $this->verifiedAt = new \DateTimeImmutable();
    }

}