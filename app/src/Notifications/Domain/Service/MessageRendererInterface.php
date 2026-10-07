<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Service;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Type\NotificationType;

interface MessageRendererInterface
{
    public function type(): NotificationType;

    public function render(NotifiableEvent $event): string;
}
