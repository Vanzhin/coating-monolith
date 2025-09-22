<?php

declare(strict_types=1);

namespace App\Users\Domain\Entity;

use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Domain\Security\AuthUserInterface;
use App\Shared\Domain\Service\UuidService;
use App\Users\Domain\Event\UserCreatedEvent;
use App\Users\Domain\Service\UserPasswordHasherInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class User extends Aggregate implements AuthUserInterface
{
    private readonly string $ulid;
    private ?string $password = null;
    private bool $isActive = false;
    /**
     * @var array<string>
     */
    private array $roles = [];

    /**
     * @var Collection<Channel>
     */
    private Collection $channels;

    public function __construct(
        private readonly string $email,
    ) {
        $this->ulid = UuidService::generateUlid();
        $this->channels = new ArrayCollection();
        $this->raise(new UserCreatedEvent($this->ulid));
    }

    public function getUlid(): string
    {
        return $this->ulid;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function eraseCredentials(): void
    {
        // TODO: Implement eraseCredentials() method.
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    public function setPassword(?string $password, UserPasswordHasherInterface $hasher): void
    {
        if (is_null($password)) {
            $this->password = null;

            return;
        }

        $this->password = $hasher->hash($this, $password);
    }

    public function getChannels(): Collection
    {
        return $this->channels;
    }

    public function addChannel(Channel $channel): void
    {
        if (!$this->channels->contains($channel)) {
            $this->channels->add($channel);
        }
    }

    public function getId(): string
    {
        return $this->ulid;
    }

    public function getVerifiedChannels(): Collection
    {
        return $this->channels->filter(function (Channel $channel) {
            return $channel->isVerified();
        });
    }

    public function makeActive(): void
    {
        if ($this->getVerifiedChannels()->isEmpty()) {
            return;
        }
        $this->isActive = true;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }
}
