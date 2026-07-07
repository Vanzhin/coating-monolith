<?php

declare(strict_types=1);

namespace App\Shared\Application\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\HttpFoundation\RequestStack;

class JWTCreatedListener
{
    private RequestStack $requestStack;

    public function __construct(RequestStack $requestStack)
    {
        $this->requestStack = $requestStack;
    }

    public function onJWTCreated(JWTCreatedEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $payload = $event->getData();
        $payload['ip'] = $request->getClientIp();

        // Lexik положил email как Email VO объект (PropertyAccess по имени поля
        // из user_id_claim = 'email' зовёт User::getEmail() и получает VO).
        // Без кастинга: при кодировании JWT сериализуется как {}, при декодировании —
        // «Array to string conversion». Email имплементирует Stringable — cast
        // берёт значение из __toString() ($this->value). Не связываемся с User
        // и его методами.
        if (isset($payload['email']) && $payload['email'] instanceof \Stringable) {
            $payload['email'] = (string) $payload['email'];
        }

        $event->setData($payload);

        $header = $event->getHeader();
        $header['cty'] = 'JWT';

        $event->setHeader($header);
    }
}
