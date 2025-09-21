<?php

declare(strict_types=1);

namespace App\Users\Domain\Repository;

use App\Users\Domain\Entity\Token;

interface TokenRepositoryInterface
{
    public function add(Token $token): void;

    public function findByTokenValueAndSubject(string $value, string $subjectId): ?Token;

    public function findBySubject(string $subjectId): ?Token;

    public function remove(Token $token): void;

    public function removeBySubject(string $subjectId): void;

}
