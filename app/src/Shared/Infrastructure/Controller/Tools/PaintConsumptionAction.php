<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller\Tools;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Публичная страница калькулятора расхода краски. Считает в браузере (Stimulus
 * consumption_calculator) — офлайн. Источник истины по физике — доменный
 * PaintConsumptionCalculator (переиспует FilmThicknessCalculator). Аноним считает базово
 * (расход л/м², всего литров); фасовка (вёдра) и масса — у авторизованного с подстановкой из покрытия.
 */
#[Route('/tools/consumption', name: 'app_tools_consumption', methods: ['GET'])]
final class PaintConsumptionAction extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('tools/consumption.html.twig');
    }
}
