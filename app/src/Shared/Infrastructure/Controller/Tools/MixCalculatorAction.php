<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller\Tools;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Публичная страница калькулятора смешивания компонентов. Считает в браузере
 * (Stimulus mix_calculator) — серверных вычислений нет. Подстановка пропорции из
 * покрытия для авторизованных — отдельный деплой.
 */
#[Route('/tools/mix', name: 'app_tools_mix', methods: ['GET'])]
final class MixCalculatorAction extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('tools/mix.html.twig');
    }
}
