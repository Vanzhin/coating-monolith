<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service;

use App\Shared\Domain\Aggregate\VerificationSubjectInterface;

interface TokenServiceInterface
{
    public function makeToken(VerificationSubjectInterface $subject): Token;

    public function verifySubjectByTokenString(string $tokenString, VerificationSubjectInterface $subject): void;
}
