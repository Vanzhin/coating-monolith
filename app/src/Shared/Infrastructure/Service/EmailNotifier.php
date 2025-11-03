<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Service;

use App\Shared\Domain\Service\Mailer;
use App\Shared\Domain\Service\NotifierInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;
use Symfony\Component\Mime\Address;

readonly class EmailNotifier implements NotifierInterface
{
    public function __construct(private Mailer $mailer)
    {
    }

    public function sendVerificationCode(Channel $channel, string $code, int $timeToUse): void
    {
        if (!$this->isSupportedChannel($channel)) {
            throw new AppException('Канал не поддерживается');
        }
        $this->mailer->sendVerificationCode(
            new Address($channel->getValue()),
            $code,
            $timeToUse / 60
        );
    }

    public function isSupportedChannel(Channel $channel): bool
    {
        return $channel->getType() === ChannelType::EMAIL;
    }
}

