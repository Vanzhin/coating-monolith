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

        self::assertStringContainsString('Иванов И.И. — срок подходит у позиций: 3, просрочено: 1.', $text);
        self::assertStringContainsString('profile=p1', $text);
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
                return 'https://app/'.$name.([] !== $parameters ? '?'.http_build_query($parameters) : '');
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
