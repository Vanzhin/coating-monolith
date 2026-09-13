<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\Channel;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Application\UseCase\Command\SubscribeWebPush\SubscribeWebPushCommand;
use App\Users\Domain\Entity\ValueObject\PushSubscription;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Приём web-push подписки от браузера (PWA). Только авторизованный (префикс /cabinet). Тело —
 * PushSubscription.toJSON() браузера ({endpoint, keys:{p256dh, auth}}). Разбор и проверка формата —
 * в VO PushSubscription; владелец = текущий юзер (определяется в хендлере).
 */
#[Route('/cabinet/push/subscribe', name: 'app_push_subscribe', methods: ['POST'])]
final class SubscribeWebPushAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        try {
            $subscription = PushSubscription::fromBrowserPayload(json_decode((string) $request->getContent(), true));
        } catch (AppException $e) {
            return new JsonResponse(['ok' => false, 'error' => $e->getMessage()], $e->getCode());
        }

        $this->commandBus->execute(new SubscribeWebPushCommand($subscription));

        return new JsonResponse(['ok' => true]);
    }
}
