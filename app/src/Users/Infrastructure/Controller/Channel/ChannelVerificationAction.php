<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\Channel;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Application\DTO\Channel\ChannelDTO;
use App\Users\Application\UseCase\Command\CreateChannel\CreateChannelCommand;
use App\Users\Application\UseCase\Command\VerifyChannel\VerifyChannelCommand;
use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Entity\User;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Users\Infrastructure\Form\ChannelVerificationFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Annotation\Route;

class ChannelVerificationAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly ChannelRepositoryInterface $channelRepository,
        #[Autowire(service: 'limiter.channel_verify_per_user')]
        private readonly RateLimiterFactory $channelVerifyPerUserLimiter,
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
            // Rate-limit перебора OTP: 5 попыток/15мин на юзера (см. framework.yaml). Считаем каждую
            // отправку валидной формы; исчерпан — не выполняем команду, показываем ошибку.
            $limit = $this->channelVerifyPerUserLimiter->create($user->getId())->consume();
            if (!$limit->isAccepted()) {
                $this->addFlash('error', sprintf(
                    'Слишком много попыток верификации. Попробуйте через %d мин.',
                    max(1, (int) ceil(($limit->getRetryAfter()->getTimestamp() - time()) / 60)),
                ));
            } else {
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
                } catch (AppException $e) {
                    // Доменную ошибку (истёк/неверный код) показываем как есть и падаем в render
                    // формы с flash. Техническое НЕ ловим — его подхватит MutationErrorListener:
                    // залогирует с ref-кодом и покажет generic-тост, не утекая внутренностями.
                    $this->addFlash('error', $e->getMessage());
                }
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
