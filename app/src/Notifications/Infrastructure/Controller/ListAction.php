<?php

declare(strict_types=1);

namespace App\Notifications\Infrastructure\Controller;

use App\Notifications\Application\UseCase\Command\MarkNotificationsRead\MarkNotificationsReadCommand;
use App\Notifications\Application\UseCase\Query\GetUserNotifications\GetUserNotificationsQuery;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Страница уведомлений пользователя (раздел профиля). Только авторизованный (префикс /cabinet).
 * Заход = «увидел»: список выбираем ДО пометки прочитанным (в нём новые ещё подсвечены), затем
 * помечаем всё прочитанным — к следующему заходу и на колоколе счётчик гаснет.
 */
#[Route('/cabinet/notifications', name: 'app_notifications_list', methods: ['GET'])]
final class ListAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
        private readonly AuthUserFetcherInterface $authUserFetcher,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $ownerUlid = $this->authUserFetcher->getAuthUserId();

        $result = $this->queryBus->execute(
            new GetUserNotificationsQuery($ownerUlid, max(1, $request->query->getInt('page', 1))),
        );

        // Живая перерисовка по пушу тянет весь блок списка (infinite-list + кнопка), чтобы номера
        // страниц/«Показать ещё» пересчитались сервером и не осталось рассинхрона.
        if ('list' === $request->query->get('fragment')) {
            return $this->render('cabinet/notifications/_list.html.twig', ['result' => $result]);
        }

        // Догрузка по «Показать ещё» тянет голый батч уведомлений.
        if ($request->query->getBoolean('partial')) {
            return $this->render('cabinet/notifications/_notifications_batch.html.twig', [
                'notifications' => $result->notifications,
            ]);
        }

        // Полная загрузка страницы = «увидел»: помечаем всё прочитанным, счётчик гаснет.
        $this->commandBus->execute(new MarkNotificationsReadCommand($ownerUlid));

        return $this->render('cabinet/notifications/index.html.twig', ['result' => $result]);
    }
}
