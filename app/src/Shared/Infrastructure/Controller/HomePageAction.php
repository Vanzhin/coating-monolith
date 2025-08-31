<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller;

use App\Shared\Infrastructure\Security\AuthUserFetcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('', name: 'app_homepage', methods: ['GET'])]
class HomePageAction extends AbstractController
{
    public function __construct(
        private readonly AuthUserFetcher $userFetcher,
    )
    {
    }

    public function __invoke(): Response
    {
        if($this->userFetcher->isAuthenticated()){
            return $this->redirectToRoute('app_cabinet');
        };

        return $this->render('home/index.html.twig');
    }
}
