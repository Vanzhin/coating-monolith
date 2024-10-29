<?php
declare(strict_types=1);


namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Message\MessageBusInterface as ExternalMessageBusInterface;
use App\Shared\Domain\Message\MessageInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;

class MessageBus implements ExternalMessageBusInterface
{
    use HandleTrait;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    #[\Override] public function executeMessages(MessageInterface ...$messages): void
    {
        foreach ($messages as $message) {
            $this->messageBus->dispatch($message);
        }
    }

    #[\Override] public function execute(Envelope $envelope): void
    {
        $this->messageBus->dispatch($envelope);
    }
}