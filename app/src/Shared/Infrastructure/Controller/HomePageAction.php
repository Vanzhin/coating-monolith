<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller;

use App\Shared\Infrastructure\Security\AuthUserFetcher;
use App\Users\Infrastructure\Form\RegistrationFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('', name: 'app_homepage', methods: ['GET'])]
class HomePageAction extends AbstractController
{
    public function __construct(
        private readonly AuthUserFetcher $userFetcher,
    ) {
    }

    public function __invoke(): Response
    {
        if ($this->userFetcher->isAuthenticated()) {
            return $this->redirectToRoute('app_cabinet');
        }

        // Форма регистрации встроена в лендинг (свап-панель) — отдаём её FormView.
        // Обработку сабмита делает RegistrationController (POST /sign-up).
        $registrationForm = $this->createForm(RegistrationFormType::class);

        return $this->render('home/index.html.twig', [
            'registrationForm' => $registrationForm->createView(),
        ]);
    }
}
