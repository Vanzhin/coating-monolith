<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Service;

use App\Shared\Domain\Aggregate\VerificationSubjectInterface;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Domain\Entity\Token;
use App\Users\Domain\Repository\TokenRepositoryInterface;
use App\Users\Domain\Service\TokenServiceInterface;
use Random\RandomException;

class TokenService implements TokenServiceInterface
{
    private const TOKEN_LIFETIME = 300; // 5 мин
    private const TOKEN_LENGTH = 6;

    public function __construct(
        private readonly TokenRepositoryInterface $tokenRepository,
    ) {
    }

    /**
     * @throws RandomException
     */
    public function makeToken(VerificationSubjectInterface $verifiable): Token
    {
        $nextTokenTime = $this->getTimeUntilNextToken($verifiable);
        AssertService::eq(
            $nextTokenTime,
            0,
            sprintf('Следующий токен может быть создан через ~%d мин.', $nextTokenTime / 60)
        );
        $token = new Token(
            token: $this->generateTokenString(),
            subjectId: $verifiable->getSubjectId(),
            expiresAt: $this->generateExpiresAt()
        );
        $this->tokenRepository->add($token);

        return $token;
    }

    public function removeToken(VerificationSubjectInterface $verifiable): void
    {
        $this->tokenRepository->removeBySubject($verifiable->getSubjectId());
    }

    public function verifySubjectByTokenString(string $tokenString, VerificationSubjectInterface $verifiable): true
    {
        // Проверяем, что объект еще не верифицирован
        if ($verifiable->isVerified()) {
            throw new AppException('Субъект уже верифицирован');
        }

        // Ищем токен для данного субъекта
        $token = $this->tokenRepository->findBySubject($verifiable->getSubjectId());

        if ($token === null) {
            throw new AppException('Токен верификации не найден или истек');
        }

        // Проверяем валидность токена
        if (!$token->isValid()) {
            $this->removeToken($verifiable);
            throw new AppException('Токен верификации истек');
        }

        // Безопасное сравнение токенов (защита от timing-атак)
        if (!$token->equals($tokenString)) {
            throw new AppException('Неверный токен верификации');
        }

        // Проверяем, что токен принадлежит именно этому субъекту
        if ($token->getSubjectId() !== $verifiable->getSubjectId()) {
            throw new AppException('Токен не соответствует субъекту верификации');
        }

        // Удаляем использованный токен
        $this->removeToken($verifiable);

        return true;
    }

    public function canCreateToken(VerificationSubjectInterface $verifiable): bool
    {
        $exist = $this->tokenRepository->findBySubject($verifiable->getSubjectId());
        if ($exist) {
            return false;
        }

        return true;
    }

    public function getTimeUntilNextToken(VerificationSubjectInterface $verifiable): int
    {
        $exist = $this->tokenRepository->findBySubject($verifiable->getSubjectId());

        if ($exist) {
            $now = new \DateTimeImmutable();
            return $exist->getExpiresAt()->getTimestamp() - $now->getTimestamp();
        }

        return 0;
    }

    /**
     * @throws RandomException
     */
    private function generateTokenString(): string
    {
        // Генерация 6-значного цифрового кода
        $min = pow(10, self::TOKEN_LENGTH - 1);
        $max = pow(10, self::TOKEN_LENGTH) - 1;

        return (string)random_int($min, $max);
    }

    private function generateExpiresAt(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        return $now->add(new \DateInterval('PT' . self::TOKEN_LIFETIME . 'S'));
    }
}