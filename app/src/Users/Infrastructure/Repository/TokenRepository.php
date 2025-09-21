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
            $this->buildKey($token->getSubjectId()),
            $token->toArray(),
            $token->getExpiresAt()->getTimestamp() - time()
        );
    }

    public function findByTokenValueAndSubject(string $value, string $subjectId): ?Token
    {
        //todo может убрать такой метод
        $data = $this->redisService->get($this->buildKey($subjectId));
        if (!$data) {
            return null;
        }

        return Token::fromArray($data);
    }

    public function findBySubject(string $subjectId): ?Token
    {
        $data = $this->redisService->get($this->buildKey($subjectId));
        if (!$data) {
            return null;
        }

        return Token::fromArray($data);
    }

    private function buildKey(string $value): string
    {
        return self::TOKEN_PREFIX . $value;
    }

    public function remove(Token $token): void
    {
        $this->removeBySubject($token->getSubjectId());
    }

    public function removeBySubject(string $subjectId): void
    {
        $this->redisService->delete($this->buildKey($subjectId));
    }
}
