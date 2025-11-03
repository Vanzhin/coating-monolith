<?php

declare(strict_types=1);

namespace App\Shared\Domain\Service;

use App\Users\Domain\Entity\Channel;

interface NotifierInterface
{
    public function sendVerificationCode(Channel $channel, string $code, int $timeToUse): void;

    public function isSupportedChannel(Channel $channel): bool;
}
