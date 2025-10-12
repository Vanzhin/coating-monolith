<?php

declare(strict_types=1);

namespace App\Users\Domain\Entity;

use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Aggregate\VerificationSubjectInterface;
use App\Users\Domain\Event\ChannelVerifiedEvent;
use App\Shared\Domain\Service\TokenServiceInterface;
use Symfony\Component\Uid\Uuid;

class Channel extends Aggregate implements VerificationSubjectInterface
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
    public function getId(): string
    {
        return $this->id->jsonSerialize();
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

    public function getSubjectId(): string
    {
        return $this->id->jsonSerialize();
    }

    public function verify(TokenServiceInterface $tokenService, string $token): void
    {
        $tokenService->verifySubjectByTokenString($token, $this);
        $this->isVerified = true;
        $this->verifiedAt = new \DateTimeImmutable();
        $this->raise(new ChannelVerifiedEvent($this->getId()));
    }
}