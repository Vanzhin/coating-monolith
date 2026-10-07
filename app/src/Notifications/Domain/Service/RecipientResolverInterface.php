<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Service;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Type\ResolverKey;

interface RecipientResolverInterface
{
    public function key(): ResolverKey;

    /** @return list<string> userUlid адресатов. */
    public function resolve(NotifiableEvent $event): array;
}
