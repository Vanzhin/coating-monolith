<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Security\LoginFormAuthenticator;
use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Application\UseCase\Command\CreateUser\CreateUserCommand;
use App\Users\Domain\Repository\UserRepositoryInterface;
use App\Users\Domain\Service\Validation\EmailValidatorInterface;
use App\Users\Infrastructure\Form\RegistrationFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly UserRepositoryInterface $userRepository,
        private readonly UserAuthenticatorInterface $userAuthenticator,
        private readonly LoginFormAuthenticator $authenticator,
        private readonly EmailValidatorInterface $emailListValidator
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
            //посторонних не пускаем
            if (!$this->emailListValidator->isValid($email)) {
                $this->addFlash('register_failure', sprintf('С почтой `%s` зарегистрироваться невозможно.', $email));
                return $this->render('security/register.html.twig', [
                    'registrationForm' => $form->createView(),
                ]);
            }

            try {
                $result = $this->commandBus->execute(new CreateUserCommand($email, $password));
            } catch (AppException $e) {
                $this->addFlash('register_failure', $e->getMessage());
                return $this->render('security/register.html.twig', [
                    'registrationForm' => $form->createView(),
                ]);
            }

            $user = $this->userRepository->find($result->ulid);

            $this->addFlash('register_success', 'Регистрация прошла успешно.');

            return $this->userAuthenticator->authenticateUser(
                $user,
                $this->authenticator,
                $request
            );
        }

        return $this->render('security/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }
}
