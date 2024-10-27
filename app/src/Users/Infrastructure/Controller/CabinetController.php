<?php

namespace App\Users\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CabinetController extends AbstractController
{
    #[Route(path: '/cabinet', name: 'app_cabinet')]
    public function cabinet(): Response
    {
        return $this->render('cabinet/index.html.twig');

    }


}
