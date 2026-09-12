<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller\Tools;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Публичный хаб раздела «Инструменты». Доступен всем (в security.yaml не значится —
 * значит PUBLIC_ACCESS). У залогиненного отрисуется в оболочке, у гостя — без неё.
 */
#[Route('/tools', name: 'app_tools_index', methods: ['GET'])]
final class ToolsIndexAction extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('tools/index.html.twig');
    }
}
