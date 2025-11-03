<?php
declare(strict_types=1);


namespace App\Users\Infrastructure\Controller\Security;

use App\Shared\Application\Event\EventBusInterface;
use App\Users\Domain\Event\LoginLinkCreatedEvent;
use App\Users\Domain\Repository\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/login_link', name: 'app_login_link')]
class LoginLinkAction extends AbstractController
{
    public function __construct(
        private readonly EventBusInterface       $eventBus,
        private readonly UserRepositoryInterface $userRepository,
    )
    {
    }

    public function __invoke(Request $request)
    {
        $email = null;
        if ($this->getUser()) {
            return $this->redirectToRoute('app_cabinet');
        }
        if ($request->isMethod('POST')) {
            $email = $request->getPayload()->get('email');
            $user = $this->userRepository->getByEmail($email);
            if (!$user) {
                $this->addFlash('register_failure', 'No user found.');
                return $this->render('security/login_link.html.twig', compact('email'));
            }

            $this->eventBus->execute(new LoginLinkCreatedEvent($user->getUlid()));
            $this->addFlash('register_success', 'Регистрация прошла успешно. email со ссылкой отправлен.');

            return $this->render('security/login_link_sent.html.twig', compact('email'));
        }
        return $this->render('security/login_link.html.twig', compact('email'));
    }

}