<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/cabinet/inquiry/new', name: 'app_cabinet_inquiry_new', methods: ['GET'])]
final class NewInquiryAction extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('cabinet/inquiry/form.html.twig');
    }
}
