<?php

declare(strict_types=1);

namespace App\Tests\Functional\Inquiries\Infrastructure\Api\NetAngels;

use App\Inquiries\Application\Port\InquiryParametersExtractor;
use App\Inquiries\Infrastructure\Api\NetAngels\NetAngelsExtractor;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Проверяет, что весь граф DI собирается: scoped-клиент netangels → обёртка
 * AuditedHttpClientFactory::wrap → NetAngelsExtractor → порт. Без сети (не зовём extract).
 */
final class NetAngelsExtractorWiringTest extends KernelTestCase
{
    public function test_port_resolves_to_netangels_extractor(): void
    {
        self::bootKernel();

        $extractor = static::getContainer()->get(InquiryParametersExtractor::class);

        self::assertInstanceOf(NetAngelsExtractor::class, $extractor);
    }
}
