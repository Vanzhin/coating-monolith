<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Render;

use App\Notifications\Domain\Event\ComplianceDueItem;
use App\Notifications\Domain\Event\ComplianceDueSoonData;
use App\Notifications\Domain\Event\DueKind;
use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\SubjectNotification;
use App\Notifications\Domain\Service\MessageRendererInterface;
use App\Notifications\Domain\Service\SummaryRendererInterface;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Текст уведомления о сроках. Персональный ({@see render}) — про одного сотрудника со счётчиками и ссылкой на
 * его карточку на дашборде. Сводный ({@see renderSummary}) — по нескольким сотрудникам: «Сотрудников: N» +
 * суммарные счётчики + ссылка на дашборд. Текст короткий, детали — на дашборде. Ссылки строит UrlGenerator;
 * хост — из router.default_uri (env DOMAIN), в коде домен не пишем.
 */
final readonly class ComplianceDueSoonRenderer implements MessageRendererInterface, SummaryRendererInterface
{
    private const DASHBOARD_ROUTE = 'app_cabinet_compliance_dashboard';

    public function __construct(private UrlGeneratorInterface $urls)
    {
    }

    public function type(): NotificationType
    {
        return NotificationType::ComplianceDueSoon;
    }

    public function render(NotifiableEvent $event): string
    {
        if (!$event instanceof ComplianceDueSoonData || !$event instanceof SubjectNotification) {
            throw new AppException('Событие compliance.due_soon не несёт нужных данных.');
        }

        [$soon, $overdue] = $this->count($event->items());
        $link = $this->urls->generate(self::DASHBOARD_ROUTE, ['profile' => $event->subjectProfileId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return sprintf("%s — %s.\n%s", $event->employeeFio(), $this->phrase($soon, $overdue), $link);
    }

    public function renderSummary(NotifiableEvent ...$events): string
    {
        $soon = 0;
        $overdue = 0;
        foreach ($events as $event) {
            if (!$event instanceof ComplianceDueSoonData) {
                throw new AppException('Событие compliance.due_soon не несёт нужных данных.');
            }
            [$s, $o] = $this->count($event->items());
            $soon += $s;
            $overdue += $o;
        }
        $link = $this->urls->generate(self::DASHBOARD_ROUTE, $this->summaryParams($soon, $overdue), UrlGeneratorInterface::ABSOLUTE_URL);

        return sprintf("Сотрудников: %d — %s.\n%s", \count($events), $this->phrase($soon, $overdue), $link);
    }

    /**
     * @param list<ComplianceDueItem> $items
     *
     * @return array{0: int, 1: int} [soon, overdue]
     */
    private function count(array $items): array
    {
        $soon = 0;
        $overdue = 0;
        foreach ($items as $item) {
            match ($item->kind) {
                DueKind::Soon => ++$soon,
                DueKind::Overdue => ++$overdue,
            };
        }

        return [$soon, $overdue];
    }

    private function phrase(int $soon, int $overdue): string
    {
        $parts = [];
        if ($soon > 0) {
            $parts[] = 'срок подходит у позиций: '.$soon;
        }
        if ($overdue > 0) {
            $parts[] = 'просрочено: '.$overdue;
        }

        return implode(', ', $parts);
    }

    /** @return array<string, string> фильтр дашборда под состав сводки */
    private function summaryParams(int $soon, int $overdue): array
    {
        if ($soon > 0 && $overdue > 0) {
            return []; // отдельного фильтра «soon+overdue» у дашборда нет — без фильтра состояния
        }
        if ($overdue > 0) {
            return ['status' => 'overdue'];
        }

        return ['status' => 'soon'];
    }
}
