<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\Channel;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Helper\ExceptionHelperTrait;
use App\Users\Application\DTO\Channel\ChannelDTO;
use App\Users\Application\UseCase\Command\CreateChannel\CreateChannelCommand;
use App\Users\Application\UseCase\Command\VerifyChannel\VerifyChannelCommand;
use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Users\Infrastructure\Form\ChannelVerificationFormType;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ChannelVerificationAction extends AbstractController
{
    use ExceptionHelperTrait;

    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('user/channel/verification', name: 'app_user_channel_verification')]
    public function verification(Request $request): Response
    {
        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        if ($user->isActive() && $user->getUnVerifiedChannels()->isEmpty()) {
            return $this->redirectToRoute('app_cabinet');
        }

        // ВАЖНО: спрашиваем у репозитория, а не у $user->getChannels(). Symfony Security
        // держит User в session с stale-коллекцией channels — после первого создания
        // на следующем запросе коллекция всё ещё может быть пустой → дубликат → unique violation.
        $emailChannel = $this->channelRepository->findOneByOwnerTypeValue(
            $user->getId(),
            ChannelType::EMAIL->value,
            $user->getEmail()->getValue(),
        );
        if (null === $emailChannel) {
            $this->createChannel($user);

            return $this->redirectToRoute('app_user_channel_verification');
        }

        $unverifiedChannels = $user->getUnVerifiedChannels();
        $firstChannel = $unverifiedChannels->isEmpty() ? null : $unverifiedChannels->first();
        $formData = null !== $firstChannel ? ['channel' => $firstChannel] : null;

        $form = $this->createForm(ChannelVerificationFormType::class, $formData, [
            'user' => $user,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Узкий try: ловим доменный AppException ровно над verify-командой.
            // Прочее (loadUser, createChannel выше) — не ловим: пусть Symfony отдаёт нормальный 500/4xx
            // с реальным сообщением вместо «Undefined $form».
            try {
                /** @var Channel $channel */
                $channel = $form->get('channel')->getData();
                $token = $form->get('token')->getData();
                $this->commandBus->execute(
                    new VerifyChannelCommand(channelId: $channel->getId(), tokenString: $token)
                );

                $this->addFlash('success', 'Канал успешно верифицирован!');
                $this->addFlash('success', 'Аккаунт успешно активирован!');

                return $this->redirectToRoute('app_cabinet');
            } catch (\Exception $e) {
                // Доменную ошибку (истёк/неверный код) показываем как есть; инфраструктурную — в лог,
                // пользователю нейтрально, без утечки внутренностей.
                $original = $this->getOriginalException($e);
                if ($original instanceof AppException) {
                    $this->addFlash('error', $original->getMessage());
                } else {
                    $this->logger->error('Ошибка верификации канала', ['exception' => $e]);
                    $this->addFlash('error', 'Не удалось завершить верификацию. Попробуйте позже.');
                }
                // Падаем в render формы с показом flash-ошибки.
            }
        }

        return $this->render('user/channel/verify.html.twig', [
            'verificationForm' => $form->createView(),
            'user' => $user,
        ]);
    }

    private function createChannel(User $user): void
    {
        $channelDto = new ChannelDTO(
            id: UuidService::generate(),
            type: ChannelType::EMAIL->value,
            value: $user->getEmail()->getValue(),
            owner_id: $user->getId(),
        );
        $command = new CreateChannelCommand($channelDto);
        $this->commandBus->execute($command);
    }
}
