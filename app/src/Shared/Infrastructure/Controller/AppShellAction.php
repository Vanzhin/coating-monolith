<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller;

use App\Shared\Infrastructure\Security\AuthUserFetcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Стартовый экран PWA. В отличие от `/` (HomePageAction), который для залогиненного делает 302 на
 * приватный `/cabinet` (некэшируемый) — эта страница ПУБЛИЧНА и НИКОГДА не редиректит на сервере,
 * поэтому её кэширует service worker и PWA открывается офлайн. Онлайн залогиненного форвардит в
 * кабинет клиентский JS (app_shell_controller), а не сервер — иначе сломался бы офлайн-кэш.
 */
#[Route('/app', name: 'app_shell', methods: ['GET'])]
final class AppShellAction extends AbstractController
{
    public function __construct(
        private readonly AuthUserFetcher $userFetcher,
    ) {
    }

    public function __invoke(): Response
    {
        return $this->render('app_shell/index.html.twig', [
            'authenticated' => $this->userFetcher->isAuthenticated(),
        ]);
    }
}
