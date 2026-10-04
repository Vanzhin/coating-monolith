<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Domain\Event\EventInterface;
use Symfony\Component\Messenger\HandleTrait;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

class EventBus implements EventBusInterface
{
    use HandleTrait;

    public function __construct(MessageBusInterface $eventBus)
    {
        $this->messageBus = $eventBus;
    }

    public function execute(EventInterface ...$events): void
    {
        // Доменное событие — факт о ЗАКОММИЧЕННОМ изменении: диспатчим после завершения текущей команды
        // (после коммита doctrine_transaction), иначе async-воркер может прочитать агрегат до коммита и
        // пересчитать проекцию из устаревшего состояния. Вне обработки команды штамп ничего не задерживает.
        foreach ($events as $event) {
            $this->messageBus->dispatch($event, [new DispatchAfterCurrentBusStamp()]);
        }
    }
}
