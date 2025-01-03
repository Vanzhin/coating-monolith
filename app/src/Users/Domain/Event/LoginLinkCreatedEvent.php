<?php
declare(strict_types=1);


namespace App\Users\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

class LoginLinkCreatedEvent implements EventInterface
{
    public function __construct(public string $userId)
    {
    }
}