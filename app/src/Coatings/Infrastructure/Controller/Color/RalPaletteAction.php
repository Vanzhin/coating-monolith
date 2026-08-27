<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Color;

use App\Coatings\Domain\Aggregate\Color\RalClassicPalette;
use App\Coatings\Domain\Aggregate\Color\RalColor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Отдаёт справочник RAL Classic для сетки выбора в модалке создания цвета.
 * Пустой q — весь каталог; иначе — поиск по коду/имени.
 */
#[Route(
    path: '/cabinet/coating/color/ral',
    name: 'app_cabinet_coating_color_ral',
    methods: ['GET'],
)]
final class RalPaletteAction extends AbstractController
{
    public function __invoke(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $colors = '' === $q ? RalClassicPalette::all() : RalClassicPalette::search($q);

        $payload = array_map(
            static fn (RalColor $ral) => ['code' => $ral->code, 'name' => $ral->name, 'hex' => $ral->hex->value],
            $colors,
        );

        return new JsonResponse($payload);
    }
}
