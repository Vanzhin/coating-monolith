<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Render;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Service\MessageRendererInterface;
use App\Shared\Infrastructure\Exception\AppException;

/** Реестр рендереров текста уведомлений: по типу события отдаёт готовый текст. */
final class MessageRendererRegistry
{
    /** @var array<string, MessageRendererInterface> */
    private array $byType = [];

    /** @param iterable<MessageRendererInterface> $renderers */
    public function __construct(iterable $renderers)
    {
        foreach ($renderers as $renderer) {
            $this->byType[$renderer->type()->value] = $renderer;
        }
    }

    public function render(NotifiableEvent $event): string
    {
        $type = $event->notificationType();

        return ($this->byType[$type->value] ?? throw new AppException('Нет рендерера: '.$type->value))->render($event);
    }
}
