<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Service;

use App\Notifications\Domain\Event\NotifiableEvent;

/**
 * Необязательный контракт рендерера: собрать СВОДНЫЙ текст из нескольких событий одного типа (перспектива
 * «про других»). Рендерер типа реализует его дополнительно к {@see MessageRendererInterface}; если не
 * реализует — события уходят получателю по одному.
 */
interface SummaryRendererInterface
{
    public function renderSummary(NotifiableEvent ...$events): string;
}
