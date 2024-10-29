<?php
declare(strict_types=1);


namespace App\Users\Infrastructure\Controller\Security;

use App\Shared\Domain\Service\RedisService;
use App\Users\Domain\Repository\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/login_by_link', name: 'app_login_by_link')]
class LoginLinkProcessAction extends AbstractController
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly RedisService            $redisService,
        private readonly Security                $security,
    )
    {
    }

    public function __invoke(Request $request)
    {
        $hash = $request->get('hash');
        if (!$hash) {
            $this->addFlash('register_failure', 'No link found.');
            return $this->render('security/login_link.html.twig', ['email' => null]);
        }
        $userId = $this->redisService->get($hash)['userUlid'] ?? null;
        if (!$userId) {
            $this->addFlash('register_failure', 'No link found or expired.');
            return $this->render('security/login_link.html.twig', ['email' => null]);
        }
        $user = $this->userRepository->find($userId);
        if (!$user) {
            $this->addFlash('register_failure', 'User not found.');
            return $this->render('security/login_link.html.twig', ['email' => null]);
        }
        $this->security->login($user, 'App\Shared\Application\Security\LoginFormAuthenticator', 'main');

        return $this->redirectToRoute('app_cabinet');
    }
}