<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Application\UseCase\Command\CreateUser\CreateUserCommand;
use App\Users\Domain\Service\Validation\EmailValidatorInterface;
use App\Users\Infrastructure\Form\RegistrationFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly EmailValidatorInterface $emailListValidator,
    ) {
    }

    #[Route('/sign-up', name: 'app_sign_up')]
    public function register(Request $request): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_cabinet');
        }
        $form = $this->createForm(RegistrationFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $password = $form->get('plainPassword')->getData();
            $email = $form->get('email')->getData();
            // посторонних не пускаем (allow-list — отдельная проверка, не про существование аккаунта)
            if (!$this->emailListValidator->isValid($email)) {
                $this->addFlash('register_failure', sprintf('С почтой `%s` зарегистрироваться невозможно.', $email));

                return $this->render('security/register.html.twig', [
                    'registrationForm' => $form->createView(),
                ]);
            }

            try {
                $this->commandBus->execute(new CreateUserCommand($email, $password));
            } catch (AppException) {
                // Анти-энумерация: НЕ подтверждаем, существует ли уже аккаунт с такой почтой.
                // Экран ниже одинаков и для нового, и для занятого адреса — оракула нет.
                // Авто-логин убран сознательно: залогинить можно только реально нового юзера,
                // а это выдало бы разницу между ветками. Ср. анти-энумерацию в LoginLinkAction.
            }

            $this->addFlash(
                'register_success',
                'Регистрация обработана. Если аккаунт с этой почтой ещё не был создан — теперь он создан. Войдите, используя указанные данные.'
            );

            return $this->render('security/register.html.twig', [
                'registrationForm' => $form->createView(),
            ]);
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }
}
