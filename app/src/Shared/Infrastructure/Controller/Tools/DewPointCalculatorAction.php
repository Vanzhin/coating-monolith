<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller\Tools;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Публичная страница калькулятора точки росы. Считает в браузере (Stimulus dew_point_calculator) —
 * серверных вычислений нет. Формула зеркалит доменный DewPointCalculator (Магнус).
 */
#[Route('/tools/dew-point', name: 'app_tools_dew', methods: ['GET'])]
final class DewPointCalculatorAction extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('tools/dew_point.html.twig');
    }
}
