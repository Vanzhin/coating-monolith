<?php

namespace App\Users\Infrastructure\Controller;

use App\Shared\Domain\Service\RedisService;
use App\Users\Domain\Repository\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;


class SecurityController extends AbstractController
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly RedisService $redisService,
    )
    {
    }

    #[Route(path: '/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_cabinet');
        }

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();
        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', ['last_username' => $lastUsername, 'error' => $error]);
    }

    #[Route(path: '/logout', name: 'app_logout')]
    public function logout(): void
    {
        throw new \LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }

    #[Route('/login_link', name: 'app_login_link')]
    public function loginLink(Request $request): Response
    {
        $email = null;
        if ($this->getUser()) {
            return $this->redirectToRoute('app_cabinet');
        }
        if ($request->isMethod('POST')) {
            $email = $request->getPayload()->get('email');
            $user = $this->userRepository->getByEmail($email);

            if (!$user){
                $this->addFlash('register_failure', 'No user found.');
                return $this->render('security/login_link.html.twig', compact('email'));
            }
            $hash = password_hash(random_bytes(10), PASSWORD_DEFAULT);
            $this->redisService->add($hash, ['userUlid'=>$user->getUlid()], 60);
            $exist = $this->redisService->get($hash);
            //todo
            dd($exist);
            $this->addFlash('register_success', 'Your registration has been passed successfully. Verification email is being sent.');

        }

        return $this->render('security/login_link.html.twig', compact('email'));
    }


}
