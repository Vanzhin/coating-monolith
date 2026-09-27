<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller\Tools;

use App\Shared\Infrastructure\SectionFactor\SortamentProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Публичный эндпоинт сортамента профилей для калькулятора ПТМ. Отдаёт дерево «стандарт → уровни
 * каскада → размеры»; общий ResponseListener оборачивает его в конверт {result,status,data}.
 * Фронт тянет один раз и кэширует у себя (localStorage) + предкэш SW → офлайн.
 * Публичность — через access_control (^/api/tools) в security.yaml.
 */
#[Route('/api/tools/section-factor/sortament', name: 'app_api_tools_section_factor_sortament', methods: ['GET'])]
final class SortamentAction extends AbstractController
{
    public function __construct(private readonly SortamentProvider $provider)
    {
    }

    public function __invoke(): Response
    {
        return JsonResponse::fromJsonString($this->provider->json());
    }
}
