<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate;

interface VerificationSubjectInterface
{
    public function getSubjectId(): string;

    public function markAsVerified(): void;

    public function isVerified(): bool;

    public function getVerifiedAt(): ?\DateTimeImmutable;
}