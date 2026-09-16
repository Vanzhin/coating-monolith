<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\EventListener\Exception;

use App\Shared\Infrastructure\EventListener\Exception\ExceptionListener;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ExceptionListenerTest extends TestCase
{
    private function listener(bool $debug): ExceptionListener
    {
        $bag = $this->createMock(ContainerBagInterface::class);
        $bag->method('get')->with('kernel.debug')->willReturn($debug);

        return new ExceptionListener($bag);
    }

    public function test_prod_masks_generic_exception_message(): void
    {
        $data = $this->listener(false)->exceptionToArray(
            new \RuntimeException('SQLSTATE[42P01]: table "users" column "password_hash"')
        );

        self::assertSame('Internal Server Error', $data['message']);
        self::assertArrayNotHasKey('trace', $data);
    }

    public function test_prod_preserves_app_exception_message(): void
    {
        $data = $this->listener(false)->exceptionToArray(new AppException('Длительность должна быть положительной.'));

        self::assertSame('Длительность должна быть положительной.', $data['message']);
    }

    public function test_prod_preserves_http_exception_message(): void
    {
        $data = $this->listener(false)->exceptionToArray(new NotFoundHttpException('Не найдено'));

        self::assertSame('Не найдено', $data['message']);
    }

    public function test_debug_exposes_real_message_and_trace(): void
    {
        $data = $this->listener(true)->exceptionToArray(new \RuntimeException('internal detail'));

        self::assertSame('internal detail', $data['message']);
        self::assertArrayHasKey('trace', $data);
    }
}
