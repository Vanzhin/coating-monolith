<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller\Tools;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Публичная страница калькулятора толщины плёнки (мокрая ↔ сухая с учётом разбавления).
 * Считает в браузере (Stimulus film_calculator) — офлайн. Источник истины по физике —
 * доменный FilmThicknessCalculator; фронт дублирует формулу ради работы без сети. Подстановка
 * сухого остатка из покрытия — авторизованным (тот же suggest, что у смешивания).
 */
#[Route('/tools/film', name: 'app_tools_film', methods: ['GET'])]
final class FilmCalculatorAction extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('tools/film.html.twig');
    }
}
