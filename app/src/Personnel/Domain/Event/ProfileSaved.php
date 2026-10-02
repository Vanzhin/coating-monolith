<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/** Профиль создан/изменён — учёт соответствия человека пересобрать и завести недостающие черновики карточек. */
final readonly class ProfileSaved implements EventInterface
{
    public function __construct(public string $profileId)
    {
    }
}
