<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Render;

use App\Notifications\Domain\Event\DeliveryPerspective;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\RecipientGroup;
use App\Notifications\Domain\Service\MessageRendererInterface;
use App\Notifications\Domain\Service\SummaryRendererInterface;
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

    /**
     * Тексты для группы получателя: перспектива «про других» и рендерер умеет сводку → один сводный текст;
     * иначе — по тексту на каждое событие группы.
     *
     * @return list<string>
     */
    public function messagesFor(RecipientGroup $group): array
    {
        $renderer = $this->byType[$group->type()->value] ?? throw new AppException('Нет рендерера: '.$group->type()->value);

        if (DeliveryPerspective::Oversight === $group->perspective && $renderer instanceof SummaryRendererInterface) {
            return [$renderer->renderSummary(...$group->events())];
        }

        return array_map(static fn (NotifiableEvent $event): string => $renderer->render($event), $group->events());
    }
}
