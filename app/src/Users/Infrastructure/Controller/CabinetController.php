<?php

namespace App\Users\Infrastructure\Controller;

use App\Users\Domain\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CabinetController extends AbstractController
{
    #[Route(path: '/cabinet', name: 'app_cabinet')]
    public function cabinet(): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->getUnVerifiedChannels()->first()) {
            return $this->redirectToRoute('app_user_channel_verification');
        }

        return $this->render('cabinet/index.html.twig');
    }


}
