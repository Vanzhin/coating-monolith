<?php

declare(strict_types=1);

namespace App\Shared\Domain\Audit;

/**
 * Порт: человекочитаемая подпись актора аудит-записи (email юзера или «Система»).
 * Реализация — в контексте Users (инверсия зависимостей: Shared не знает про Users).
 */
interface ActorResolverInterface
{
    public function resolve(string $actorId): string;
}
