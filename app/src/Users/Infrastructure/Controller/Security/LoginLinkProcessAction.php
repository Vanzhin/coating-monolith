<?php
declare(strict_types=1);


namespace App\Users\Infrastructure\Controller\Security;

use App\Shared\Domain\Service\RedisService;
use App\Users\Domain\Repository\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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

    public function __invoke(Request $request): Response
    {
        $hash = $request->get('hash');
        if (!$hash) {
            return $this->invalidLinkResponse();
        }
        $userId = $this->redisService->get($hash)['userUlid'] ?? null;
        if (!$userId) {
            return $this->invalidLinkResponse();
        }
        $user = $this->userRepository->find($userId);
        if (!$user) {
            // Юзер удалён между отправкой ссылки и её использованием — стираем
            // hash тоже, чтобы висящий ключ не жил свой TTL зря.
            $this->redisService->delete($hash);
            return $this->invalidLinkResponse();
        }

        // Single-use: удаляем hash СРАЗУ после успешного лукапа, до реального
        // логина. Даже если что-то упадёт дальше — ссылка уже сожжена, повторный
        // клик даст «ссылка недействительна».
        $this->redisService->delete($hash);

        $this->security->login($user, 'App\Shared\Application\Security\LoginFormAuthenticator', 'main');

        return $this->redirectToRoute('app_cabinet');
    }

    private function invalidLinkResponse(): Response
    {
        return $this->render('security/login_link.html.twig', [
            'email' => null,
            'error' => 'Ссылка недействительна или уже была использована. Запросите новую.',
        ]);
    }
}
