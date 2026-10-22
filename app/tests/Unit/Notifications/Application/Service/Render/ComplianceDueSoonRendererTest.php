<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Application\Service\Render;

use App\Compliance\Domain\Event\ComplianceDueSoon;
use App\Notifications\Application\Service\Render\ComplianceDueSoonRenderer;
use App\Notifications\Domain\Event\ComplianceDueItem;
use App\Notifications\Domain\Event\DueKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

final class ComplianceDueSoonRendererTest extends TestCase
{
    private ComplianceDueSoonRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ComplianceDueSoonRenderer($this->urls());
    }

    public function test_personal_counts_and_link(): void
    {
        $event = new ComplianceDueSoon('p1', 'Иванов И.И.',
            new ComplianceDueItem('Перчатки', DueKind::Soon, '01.12.2026'),
            new ComplianceDueItem('Каска', DueKind::Soon, '02.12.2026'),
            new ComplianceDueItem('Костюм', DueKind::Soon, '03.12.2026'),
            new ComplianceDueItem('Обувь', DueKind::Overdue, '01.01.2025'),
        );

        $text = $this->renderer->render($event);

        self::assertStringStartsWith('Контроль обязанностей сотрудников', $text, 'первой строкой — общий заголовок типа');
        self::assertStringContainsString('Иванов И.И. — срок подходит у позиций: 3, просрочено: 1.', $text);
        self::assertStringContainsString('profile=p1', $text);
    }

    public function test_link_is_relative_path_not_absolute_url(): void
    {
        $event = new ComplianceDueSoon('p1', 'Иванов И.И.',
            new ComplianceDueItem('Перчатки', DueKind::Soon, '01.12.2026'),
        );

        $text = $this->renderer->render($event);

        // Ссылка отдаётся путём (ABSOLUTE_PATH) — абсолютизирует только письмо; в приложении путь резолвит браузер.
        self::assertStringContainsString('/cabinet/compliance/dashboard', $text);
        self::assertStringNotContainsString('https://', $text, 'хост не зашиваем — ссылка относительная');
    }

    public function test_personal_skips_zero_counter(): void
    {
        $event = new ComplianceDueSoon('p1', 'Иванов И.И.',
            new ComplianceDueItem('Перчатки', DueKind::Soon, '01.12.2026'),
            new ComplianceDueItem('Каска', DueKind::Soon, '02.12.2026'),
        );

        $text = $this->renderer->render($event);

        self::assertStringContainsString('срок подходит у позиций: 2', $text);
        self::assertStringNotContainsString('просрочено', $text);
        self::assertStringNotContainsString('не выполнено', $text);
    }

    public function test_personal_counts_missing(): void
    {
        $event = new ComplianceDueSoon('p1', 'Иванов И.И.',
            new ComplianceDueItem('Перчатки', DueKind::Soon, '01.12.2026'),
            new ComplianceDueItem('Обувь', DueKind::Overdue, '01.01.2025'),
            new ComplianceDueItem('Инструктаж', DueKind::Missing, ''),
            new ComplianceDueItem('Костюм', DueKind::Missing, ''),
        );

        $text = $this->renderer->render($event);

        self::assertStringContainsString('срок подходит у позиций: 1, просрочено: 1, не выполнено: 2.', $text);
    }

    public function test_summary_missing_only_links_to_missing_filter(): void
    {
        $event = new ComplianceDueSoon('p1', 'Иванов',
            new ComplianceDueItem('Инструктаж', DueKind::Missing, ''),
            new ComplianceDueItem('Костюм', DueKind::Missing, ''),
        );

        $text = $this->renderer->renderSummary($event);

        self::assertStringContainsString('Сотрудников: 1 — не выполнено: 2.', $text);
        self::assertStringContainsString('status=missing', $text);
    }

    public function test_summary_sums_across_events(): void
    {
        $a = new ComplianceDueSoon('p1', 'Иванов',
            new ComplianceDueItem('x', DueKind::Soon, '01.12.2026'),
            new ComplianceDueItem('y', DueKind::Soon, '02.12.2026'),
            new ComplianceDueItem('z', DueKind::Overdue, '01.01.2025'),
        );
        $b = new ComplianceDueSoon('p2', 'Петров',
            new ComplianceDueItem('q', DueKind::Overdue, '01.01.2025'),
            new ComplianceDueItem('w', DueKind::Overdue, '02.01.2025'),
            new ComplianceDueItem('e', DueKind::Overdue, '03.01.2025'),
        );

        $text = $this->renderer->renderSummary($a, $b);

        self::assertStringContainsString('Сотрудников: 2 — срок подходит у позиций: 2, просрочено: 4.', $text);
    }

    private function urls(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            /** @param array<string, mixed> $parameters */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                // Рендерер зовёт один маршрут — дашборд; имитируем Symfony: ABSOLUTE_PATH даёт путь, ABSOLUTE_URL — с хостом.
                $path = '/cabinet/compliance/dashboard'.([] !== $parameters ? '?'.http_build_query($parameters) : '');

                return self::ABSOLUTE_URL === $referenceType ? 'https://app.example.com'.$path : $path;
            }

            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        };
    }
}
