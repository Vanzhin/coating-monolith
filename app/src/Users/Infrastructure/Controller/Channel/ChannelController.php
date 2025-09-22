<?php

namespace App\Users\Infrastructure\Controller\Channel;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Service\Mailer;
use App\Users\Application\Service\AccessControl\ChannelAccessControl;
use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Users\Domain\Service\TokenServiceInterface;
use App\Users\Infrastructure\Form\ChannelVerificationFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Annotation\Route;

class ChannelController extends AbstractController
{
    public function __construct(
        private readonly TokenServiceInterface $tokenService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly ChannelAccessControl $channelAccessControl,
        private readonly Mailer $mailer,
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('user/channel/verification', name: 'app_user_channel_verification')]
    public function verification(Request $request): Response
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        if ($user->isActive()) {
            return $this->redirectToRoute('app_cabinet');
        }

        $form = $this->createForm(ChannelVerificationFormType::class, null, [
            'user' => $user,
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $token = $data['token'];
            /** @var Channel $channel */
            $channel = $data['channel'];
            dd($token, $channel);
            try {
                dd(112121);
                if ($verificationService->verifyToken($user, $channel, $token)) {
                    $this->addFlash('success', 'Канал успешно верифицирован!');

                    if (!$user->getVerifiedChannels()->isEmpty()) {
                        $user->makeActive();
                        $this->entityManager->flush();

                        if ($user->isActive()) {
                            $this->addFlash('success', 'Аккаунт успешно активирован!');
                            return $this->redirectToRoute('app_cabinet');
                        }
                    }

                    // Если верифицирован, но аккаунт еще не активен
                    return $this->redirectToRoute('app_verification');
                } else {
                    $this->addFlash('error', 'Неверный код верификации. Попробуйте еще раз.');
                }
            } catch (\Exception $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('user/channel/verify.html.twig', [
            'verificationForm' => $form->createView(),
            'user' => $user,
        ]);
    }

    #[Route('user/channel/verification/send-token', name: 'app_user_channel_verification_send_token', methods: ['POST'])]
    public function sendVerificationToken(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->json([
                'success' => false,
                'message' => 'Пользователь не авторизован'
            ], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $channelId = $data['channel_id'] ?? null;

        if (!$channelId) {
            return $this->json([
                'success' => false,
                'message' => 'Канал не указан'
            ], Response::HTTP_BAD_REQUEST);
        }

        $channel = $this->channelRepository->find($channelId);
        if (!$channel) {
            return $this->json([
                'success' => false,
                'message' => 'Канал не найден'
            ], Response::HTTP_NOT_FOUND);
        }

        /** @var Channel $channel */
        if (!$this->channelAccessControl->canView($channel)) {
            return $this->json([
                'success' => false,
                'message' => 'Запрещено'
            ], Response::HTTP_FORBIDDEN);
        }

        if ($channel->isVerified()) {
            return $this->json([
                'success' => false,
                'message' => 'Канал уже верифицирован'
            ], Response::HTTP_BAD_REQUEST);
        }

        $cooldownRemaining = $this->tokenService->getTimeUntilNextToken($channel);
        if ($cooldownRemaining > 0) {
            return $this->json([
                'success' => false,
                'message' => 'Повторная отправка возможна через ' . ceil($cooldownRemaining / 60) . ' минут',
                'cooldown_remaining' => $cooldownRemaining
            ], Response::HTTP_BAD_REQUEST);
        }
        $token = $this->tokenService->makeToken($channel);
        try {
            $this->mailer->sendVerificationCode(new Address($channel->getValue()), $token->getToken(), $token->getRemainingTime()->i);
            return $this->json([
                'success' => true,
                'message' => 'Код верификации отправлен!',
                'cooldown_remaining' => $token->getRemainingTime()->s // 5 минут в секундах
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => 'Ошибка при отправке кода: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
