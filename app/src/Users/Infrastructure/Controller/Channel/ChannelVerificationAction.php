<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\Channel;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Helper\ExceptionHelperTrait;
use App\Users\Application\UseCase\Command\VerifyChannel\VerifyChannelCommand;
use App\Users\Domain\Entity\Channel;
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
            try {
                $command = new VerifyChannelCommand(channelId: $channel->getId(), tokenString: $token);
                $this->commandBus->execute($command);

                $this->addFlash('success', 'Канал успешно верифицирован!');
                $this->addFlash('success', 'Аккаунт успешно активирован!');
                return $this->redirectToRoute('app_cabinet');
            } catch (\Exception $e) {
                $this->addFlash('error', $this->getOriginalExceptionMessage($e));
            }
        }

        return $this->render('user/channel/verify.html.twig', [
            'verificationForm' => $form->createView(),
            'user' => $user,
        ]);
    }
}
