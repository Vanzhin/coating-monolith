<?php

declare(strict_types=1);

namespace App\Users\Domain\Service;

use App\Shared\Domain\Aggregate\VerificationSubjectInterface;
use App\Users\Domain\Entity\Token;

interface TokenServiceInterface
{
    /**
     * Создает и сохраняет токен верификации для субъекта
     * Удаляет старые токены и проверяет ограничения по времени
     */
    public function makeToken(VerificationSubjectInterface $verifiable): Token;

    /**
     * Удаляет все токены для указанного объекта
     */
    public function removeToken(VerificationSubjectInterface $verifiable): void;

    /**
     * Проверяет токен для конкретного объекта и возвращает верифицированный субъект
     *
     * @throws \InvalidArgumentException Если токен невалиден
     * @throws \DomainException Если токен просрочен или не принадлежит субъект
     * @throws \DomainException Если субъект уже верифицирован
     */
    public function verifySubjectByTokenString(string $tokenString, VerificationSubjectInterface $verifiable): true;

    /**
     * Проверяет, можно ли создать новый токен для субъект
     */
    public function canCreateToken(VerificationSubjectInterface $verifiable): bool;

    /**
     * Возвращает время, оставшееся до возможности создания нового токена
     */
    public function getTimeUntilNextToken(VerificationSubjectInterface $verifiable): int;

}