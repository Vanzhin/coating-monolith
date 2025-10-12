<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate;

use App\Shared\Domain\Service\TokenServiceInterface;

interface VerificationSubjectInterface
{
    public function getSubjectId(): string;

    public function verify(TokenServiceInterface $tokenService, string $token): void;

    public function isVerified(): bool;

    public function getVerifiedAt(): ?\DateTimeImmutable;
}