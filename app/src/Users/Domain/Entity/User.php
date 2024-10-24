<?php

declare(strict_types=1);

namespace App\Users\Domain\Entity;

use App\Shared\Domain\Security\AuthUserInterface;
use App\Shared\Domain\Service\UlidService;
use App\Users\Domain\Service\UserPasswordHasherInterface;

class User implements AuthUserInterface
{
    private readonly string $ulid;
    private ?string $password = null;
    /**
     * @var array<string>
     */
    private array $roles = [];

    public function __construct(
        private readonly string $email,
    ) {
        $this->ulid = UlidService::generate();
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
}
