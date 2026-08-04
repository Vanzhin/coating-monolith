<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Event;

use App\Coatings\Domain\Event\CoatingSystemMutated;
use App\Shared\Domain\Event\EventInterface;
use PHPUnit\Framework\TestCase;

final class CoatingSystemMutatedTest extends TestCase
{
    public function test_stores_system_id_and_implements_marker(): void
    {
        $event = new CoatingSystemMutated('018f-abc');
        self::assertSame('018f-abc', $event->systemId);
        self::assertInstanceOf(EventInterface::class, $event);
    }
}
