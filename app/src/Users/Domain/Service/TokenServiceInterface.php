<?php

declare(strict_types=1);

namespace App\Users\Domain\Service;

use App\Shared\Domain\Aggregate\VerificationSubjectInterface;
use App\Users\Domain\Entity\Token;

interface TokenServiceInterface
{
    /**
     * Создает и сохраняет токен верификации для объекта
     * Удаляет старые токены и проверяет ограничения по времени
     */
    public function makeToken(VerificationSubjectInterface $verifiable): Token;

    /**
     * Удаляет все токены для указанного объекта
     */
    public function removeToken(VerificationSubjectInterface $verifiable): void;

    /**
     * Проверяет токен для конкретного объекта и возвращает верифицированный объект
     *
     * @throws \InvalidArgumentException Если токен невалиден
     * @throws \DomainException Если токен просрочен или не принадлежит объекту
     * @throws \DomainException Если объект уже верифицирован
     */
    public function verifySubjectByTokenString(string $tokenString, VerificationSubjectInterface $verifiable): true;

    /**
     * Проверяет, можно ли создать новый токен для объекта
     */
    public function canCreateToken(VerificationSubjectInterface $verifiable): bool;

    /**
     * Возвращает время, оставшееся до возможности создания нового токена
     */
    public function getTimeUntilNextToken(VerificationSubjectInterface $verifiable): int;

}