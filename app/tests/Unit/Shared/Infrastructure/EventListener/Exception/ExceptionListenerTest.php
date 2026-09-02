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
    public function test_prod_hides_raw_message_of_internal_exception(): void
    {
        $listener = $this->listenerWithDebug(false);

        $data = $listener->exceptionToArray(new \RuntimeException("SELECT * FROM users WHERE secret='x'"));

        self::assertSame('Внутренняя ошибка сервера.', $data['message']);
        self::assertArrayNotHasKey('file', $data);
        self::assertArrayNotHasKey('trace', $data);
    }

    public function test_prod_keeps_app_exception_message(): void
    {
        $listener = $this->listenerWithDebug(false);

        $data = $listener->exceptionToArray(new AppException('Читаемое сообщение для пользователя.'));

        self::assertSame('Читаемое сообщение для пользователя.', $data['message']);
    }

    public function test_prod_keeps_http_exception_message(): void
    {
        $listener = $this->listenerWithDebug(false);

        $data = $listener->exceptionToArray(new NotFoundHttpException('Not found'));

        self::assertSame('Not found', $data['message']);
    }

    public function test_debug_exposes_raw_message_and_trace(): void
    {
        $listener = $this->listenerWithDebug(true);

        $data = $listener->exceptionToArray(new \RuntimeException('secret'));

        self::assertSame('secret', $data['message']);
        self::assertArrayHasKey('file', $data);
        self::assertArrayHasKey('trace', $data);
    }

    private function listenerWithDebug(bool $debug): ExceptionListener
    {
        $bag = $this->createMock(ContainerBagInterface::class);
        $bag->method('get')->with('kernel.debug')->willReturn($debug);

        return new ExceptionListener($bag);
    }
}
