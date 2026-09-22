<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\EventListener\Exception;

use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Http\RequestFormat;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Небезопасные HTML-мутации (POST/PUT/DELETE): необработанная ошибка НЕ роняет страницу 500, а
 * превращается в flash + redirect назад — пользователь остаётся на своей странице, видит понятный
 * тост. AppException (в т.ч. Forbidden) → её доменный текст; неожиданное → лог с ref-кодом + generic
 * сообщение (без утечки внутренностей БД/стека). GET и JSON не трогаем (свои error-страницы/листенер).
 * Приоритет выше Forbidden(200)/AppExceptionHtml(195) + stopPropagation, чтобы они не перерисовали ответ.
 * Формы create/update опт-аутятся тем, что ловят AppException в контроллере (inline-ошибка) до листенера.
 */
#[AsEventListener(event: 'kernel.exception', priority: 210)]
final class MutationErrorListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if ($this->debug) {
            return; // dev — стектрейс Symfony не трогаем
        }
        $request = $event->getRequest();
        if (RequestFormat::expectsJson($request)) {
            return; // JSON отдаёт ExceptionListener
        }
        if (!RequestFormat::isHtml($request) || $request->isMethodSafe()) {
            return; // не HTML или GET/HEAD → дефолтные error-страницы
        }
        if (!$request->hasSession()) {
            return; // некуда положить flash — деградируем на error-страницу
        }

        $throwable = $event->getThrowable();
        if ($throwable instanceof AppException) {
            $message = $throwable->getMessage(); // читаемое доменное сообщение (в т.ч. Forbidden)
        } else {
            $ref = bin2hex(random_bytes(4));
            $this->logger->error($throwable->getMessage(), [
                'ref' => $ref,
                'exception' => $throwable,
                'route' => $request->attributes->get('_route'),
                'method' => $request->getMethod(),
            ]);
            $message = sprintf('Что-то пошло не так. Мы уже разбираемся — попробуйте ещё раз. Код: %s', $ref);
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('danger', $message);
        }

        $event->setResponse(new RedirectResponse($this->safeTarget($request)));
        $event->stopPropagation();
    }

    /** Referer, но только same-origin (защита от open-redirect); иначе — на главную. */
    private function safeTarget(Request $request): string
    {
        $referer = (string) $request->headers->get('Referer');
        if ('' !== $referer && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $referer;
        }

        return $this->urlGenerator->generate('app_homepage');
    }
}
