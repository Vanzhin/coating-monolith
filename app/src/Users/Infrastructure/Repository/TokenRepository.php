<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Repository;

use App\Shared\Domain\Service\RedisService;
use App\Users\Domain\Entity\Token;
use App\Users\Domain\Repository\TokenRepositoryInterface;

class TokenRepository implements TokenRepositoryInterface
{
    private const TOKEN_PREFIX = 'token:';

    public function __construct(private readonly RedisService $redisService)
    {
    }

    public function add(Token $token): void
    {
        $this->redisService->set(
            $this->buildKey($token->getToken(), $token->getSubjectId()),
            $token->toArray(),
            $token->getExpiresAt()->getTimestamp() - time()
        );
    }

    public function findByTokenValueAndSubject(string $value, string $subjectId): ?Token
    {
        $data = $this->redisService->get($this->buildKey($value, $subjectId));
        if (!$data) {
            return null;
        }

        return Token::fromArray($data);
    }

    public function findBySubject(string $subjectId): ?Token
    {
        $data = $this->redisService->get($this->buildKey(null, $subjectId));
        if (!$data) {
            return null;
        }

        return Token::fromArray($data);
    }

    private function buildKey(?string ...$values): string
    {
        $keyData = array_map(fn($item) => $item ?? '*', $values);

        return self::TOKEN_PREFIX . implode(':', $keyData);
    }
}
