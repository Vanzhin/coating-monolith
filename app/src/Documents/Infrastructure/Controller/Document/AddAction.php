<?php

declare(strict_types=1);

namespace App\Documents\Infrastructure\Controller\Document;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/cabinet/document', name: 'app_cabinet_document_create')]
class AddAction extends AbstractController
{
    public function __invoke(Request $request): Response
    {
        // todo реализация формы создания документа
        return $this->render('cabinet/proposal/create.html.twig');
    }
}
