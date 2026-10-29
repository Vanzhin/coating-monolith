<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Application\Service\Render;

use App\Notifications\Application\Service\Render\InquiryProcessedRenderer;
use App\Notifications\Domain\Event\InquiryProcessedNotification;
use App\Notifications\Domain\Type\NotificationType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InquiryProcessedRendererTest extends TestCase
{
    public function test_type(): void
    {
        self::assertSame(NotificationType::InquiryProcessed, $this->renderer()->type());
    }

    public function test_recognized_text_with_link(): void
    {
        $text = $this->renderer()->render(new InquiryProcessedNotification('owner-ulid', 'inq-1', false));

        self::assertStringContainsString('распознан', $text);
        self::assertStringContainsString('/cabinet/inquiry/inq-1', $text);
    }

    public function test_failed_text_with_reason(): void
    {
        $text = $this->renderer()->render(new InquiryProcessedNotification('owner-ulid', 'inq-1', true, 'таймаут модели'));

        self::assertStringContainsString('не удалось обработать', $text);
        self::assertStringContainsString('таймаут модели', $text);
        self::assertStringContainsString('/cabinet/inquiry/inq-1', $text);
    }

    private function renderer(): InquiryProcessedRenderer
    {
        $urls = $this->createMock(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(
            static fn (string $route, array $params): string => '/cabinet/inquiry/'.$params['id'],
        );

        return new InquiryProcessedRenderer($urls);
    }
}
