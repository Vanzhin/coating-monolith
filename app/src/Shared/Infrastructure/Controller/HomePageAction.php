<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('', name: 'app_homepage', methods: ['GET'])]
class HomePageAction extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('home/index.html.twig');
    }
}
