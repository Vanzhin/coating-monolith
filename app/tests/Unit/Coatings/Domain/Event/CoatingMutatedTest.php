<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coatings\Domain\Event;

use App\Coatings\Domain\Event\CoatingMutated;
use App\Shared\Domain\Event\EventInterface;
use PHPUnit\Framework\TestCase;

final class CoatingMutatedTest extends TestCase
{
    public function test_stores_coating_id_and_implements_marker(): void
    {
        $event = new CoatingMutated('018f-def');
        self::assertSame('018f-def', $event->coatingId);
        self::assertInstanceOf(EventInterface::class, $event);
    }
}
