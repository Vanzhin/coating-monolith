<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller\Tools;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Публичная страница калькулятора приведённой толщины металла. Считает в браузере (Stimulus
 * section_factor_calculator) — офлайн; формулы зеркалят домен Shared\Domain\Service\SectionFactor\*.
 * Сортамент грузится с публичного эндпоинта и кэшируется у клиента.
 */
#[Route('/tools/section-factor', name: 'app_tools_section_factor', methods: ['GET'])]
final class SectionFactorAction extends AbstractController
{
    public function __invoke(): Response
    {
        return $this->render('tools/section_factor.html.twig');
    }
}
