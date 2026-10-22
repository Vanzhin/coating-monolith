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

        [$soon, $overdue, $missing] = $this->count($event->items());
        $link = $this->urls->generate(self::DASHBOARD_ROUTE, ['profile' => $event->subjectProfileId()], UrlGeneratorInterface::ABSOLUTE_PATH);

        return sprintf("%s\n%s — %s.\n%s", $this->heading(), $event->employeeFio(), $this->phrase($soon, $overdue, $missing), $link);
    }

    public function renderSummary(NotifiableEvent ...$events): string
    {
        $soon = 0;
        $overdue = 0;
        $missing = 0;
        foreach ($events as $event) {
            if (!$event instanceof ComplianceDueSoonData) {
                throw new AppException('Событие compliance.due_soon не несёт нужных данных.');
            }
            [$s, $o, $m] = $this->count($event->items());
            $soon += $s;
            $overdue += $o;
            $missing += $m;
        }
        $link = $this->urls->generate(self::DASHBOARD_ROUTE, $this->summaryParams($soon, $overdue, $missing), UrlGeneratorInterface::ABSOLUTE_PATH);

        return sprintf("%s\nСотрудников: %d — %s.\n%s", $this->heading(), \count($events), $this->phrase($soon, $overdue, $missing), $link);
    }

    /** Общий заголовок первой строкой (и в приложении, и в письме) — что это за уведомление; из каталога типов. */
    private function heading(): string
    {
        return $this->type()->label();
    }

    /**
     * @param list<ComplianceDueItem> $items
     *
     * @return array{0: int, 1: int, 2: int} [soon, overdue, missing]
     */
    private function count(array $items): array
    {
        $soon = 0;
        $overdue = 0;
        $missing = 0;
        foreach ($items as $item) {
            match ($item->kind) {
                DueKind::Soon => ++$soon,
                DueKind::Overdue => ++$overdue,
                DueKind::Missing => ++$missing,
            };
        }

        return [$soon, $overdue, $missing];
    }

    private function phrase(int $soon, int $overdue, int $missing): string
    {
        $parts = [];
        if ($soon > 0) {
            $parts[] = 'срок подходит у позиций: '.$soon;
        }
        if ($overdue > 0) {
            $parts[] = 'просрочено: '.$overdue;
        }
        if ($missing > 0) {
            $parts[] = 'не выполнено: '.$missing;
        }

        return implode(', ', $parts);
    }

    /**
     * Фильтр дашборда под состав сводки: ровно одно состояние — фильтруем по нему (дашборд принимает
     * status=soon|overdue|missing); смешанный состав — без фильтра (отдельного «несколько состояний» нет).
     *
     * @return array<string, string>
     */
    private function summaryParams(int $soon, int $overdue, int $missing): array
    {
        $present = array_filter([
            'soon' => $soon,
            'overdue' => $overdue,
            'missing' => $missing,
        ], static fn (int $n): bool => $n > 0);

        return 1 === \count($present) ? ['status' => array_key_first($present)] : [];
    }
}
