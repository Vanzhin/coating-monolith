<?php

namespace App\Users\Infrastructure\Controller;

use App\Shared\Application\Security\LoginFormAuthenticator;
use App\Users\Domain\Factory\UserFactory;
use App\Users\Domain\Repository\UserRepositoryInterface;
use App\Users\Infrastructure\Form\RegistrationFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserFactory                $factory,
        private readonly UserRepositoryInterface    $userRepository,
        private readonly UserAuthenticatorInterface $userAuthenticator,
        private readonly LoginFormAuthenticator     $authenticator
    )
    {
    }

    #[Route('/sign-up', name: 'app_sign_up')]
    public function register(Request $request,

    ): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_cabinet');
        }
        $form = $this->createForm(RegistrationFormType::class);
        $form->handleRequest($request);
//
        if ($form->isSubmitted() && $form->isValid()) {

            $password = $form->get('plainPassword')->getData();
            $email = $form->get('email')->getData();

            if ($this->userRepository->getByEmail($email)){
                $this->addFlash('register_failure', 'User already exists!');
                return $this->render('security/register.html.twig', [
                    'registrationForm' => $form->createView(),
                ]);
            }
            $user = $this->factory->create($email, $password);
            $this->userRepository->add($user);

            $this->addFlash('register_success', 'Your registration has been passed successfully. Verification email is being sent.');

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
