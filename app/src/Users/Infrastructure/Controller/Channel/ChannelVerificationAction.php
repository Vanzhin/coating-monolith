<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\Channel;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Helper\ExceptionHelperTrait;
use App\Users\Application\DTO\Channel\ChannelDTO;
use App\Users\Application\UseCase\Command\CreateChannel\CreateChannelCommand;
use App\Users\Application\UseCase\Command\VerifyChannel\VerifyChannelCommand;
use App\Users\Domain\Entity\Channel;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Entity\User;
use App\Users\Infrastructure\Form\ChannelVerificationFormType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class ChannelVerificationAction extends AbstractController
{
    use ExceptionHelperTrait;

    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    #[Route('user/channel/verification', name: 'app_user_channel_verification')]
    public function verification(Request $request): Response
    {
        try {
            /** @var User $user */
            $user = $this->getUser();

            if (!$user) {
                return $this->redirectToRoute('app_login');
            }

            if ($user->isActive() && $user->getUnVerifiedChannels()->isEmpty()) {
                return $this->redirectToRoute('app_cabinet');
            }

            if ($user->getChannels()->isEmpty()) {
                $this->createChannel($user);
                return $this->redirectToRoute('app_user_channel_verification');
            }

            // Получаем первый неверифицированный канал для автоматического выбора
            $unverifiedChannels = $user->getUnVerifiedChannels();
            $firstChannel = $unverifiedChannels->isEmpty() ? null : $unverifiedChannels->first();

            // Подготавливаем данные формы с выбранным первым каналом
            $formData = null;
            if ($firstChannel) {
                $formData = [
                    'channel' => $firstChannel,
                ];
            }

            $form = $this->createForm(ChannelVerificationFormType::class, $formData, [
                'user' => $user,
            ]);

            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                $token = $data['token'];
                /** @var Channel $channel */
                $channel = $data['channel'];
                $command = new VerifyChannelCommand(channelId: $channel->getId(), tokenString: $token);
                $this->commandBus->execute($command);

                $this->addFlash('success', 'Канал успешно верифицирован!');
                $this->addFlash('success', 'Аккаунт успешно активирован!');
                return $this->redirectToRoute('app_cabinet');
            }
        } catch (\Exception $e) {
            $this->addFlash('error', $this->getOriginalExceptionMessage($e));
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
