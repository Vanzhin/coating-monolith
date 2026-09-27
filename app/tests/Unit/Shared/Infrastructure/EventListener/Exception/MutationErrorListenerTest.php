<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\EventListener\Exception;

use App\Shared\Infrastructure\EventListener\Exception\MutationErrorListener;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MutationErrorListenerTest extends TestCase
{
    private const HOME = '/';
    private const HOST = 'http://localhost';

    /** @var list<array{mixed, string|\Stringable, array<string,mixed>}> */
    private array $logs = [];

    public function test_app_exception_on_unsafe_html_redirects_back_with_flash(): void
    {
        $session = $this->session();
        $event = $this->dispatch(new AppException('Покрытие используется в системах.'), 'POST', $session, referer: self::HOST.'/cabinet/coating');

        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(self::HOST.'/cabinet/coating', $response->getTargetUrl()); // назад на referer
        self::assertTrue($event->isPropagationStopped());
        self::assertSame(['Покрытие используется в системах.'], $session->getFlashBag()->peek('danger'));
        self::assertSame([], $this->logs); // доменную ошибку не логируем
    }

    public function test_forbidden_on_unsafe_html_also_redirects_with_flash(): void
    {
        $session = $this->session();
        $event = $this->dispatch(new ForbiddenException('Недостаточно прав.'), 'POST', $session);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        self::assertSame(['Недостаточно прав.'], $session->getFlashBag()->peek('danger'));
    }

    public function test_unexpected_error_is_logged_with_ref_and_generic_flash(): void
    {
        $session = $this->session();
        $event = $this->dispatch(new \RuntimeException('mkdir(): Permission denied'), 'POST', $session);

        self::assertInstanceOf(RedirectResponse::class, $event->getResponse());
        $flash = $session->getFlashBag()->peek('danger')[0] ?? '';
        self::assertStringContainsString('Код:', $flash);
        self::assertStringNotContainsString('Permission denied', $flash); // внутренности не утекают

        self::assertCount(1, $this->logs);
        [, $loggedMessage, $context] = $this->logs[0];
        self::assertSame('mkdir(): Permission denied', $loggedMessage); // реальная ошибка — в лог
        self::assertArrayHasKey('ref', $context);
        self::assertStringContainsString($context['ref'], $flash); // код в тосте = код в логе
    }

    public function test_get_request_is_ignored(): void
    {
        $event = $this->dispatch(new \RuntimeException('boom'), 'GET', $this->session());
        self::assertNull($event->getResponse());
    }

    public function test_json_request_is_ignored(): void
    {
        $event = $this->dispatch(new \RuntimeException('boom'), 'POST', $this->session(), accept: 'application/json');
        self::assertNull($event->getResponse());
    }

    public function test_debug_bypasses(): void
    {
        $event = $this->dispatch(new \RuntimeException('boom'), 'POST', $this->session(), debug: true);
        self::assertNull($event->getResponse());
    }

    private function session(): Session
    {
        return new Session(new MockArraySessionStorage());
    }

    private function dispatch(\Throwable $e, string $method, Session $session, string $accept = 'text/html', ?string $referer = null, bool $debug = false): ExceptionEvent
    {
        $request = Request::create('http://localhost/cabinet/x', $method);
        $request->headers->set('Accept', $accept);
        if (null !== $referer) {
            $request->headers->set('Referer', $referer);
        }
        $request->setSession($session);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn(self::HOME);

        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string|\Stringable, array<string,mixed>}> */
            public array $entries = [];

            /** @param array<string,mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->entries[] = [$level, $message, $context];
            }
        };

        $listener = new MutationErrorListener($logger, $urlGenerator, $debug);
        $event = new ExceptionEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $e);
        $listener($event);
        $this->logs = $logger->entries;

        return $event;
    }
}
