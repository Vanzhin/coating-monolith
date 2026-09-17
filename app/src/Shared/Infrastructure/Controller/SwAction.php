<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\WebpackEncoreBundle\Asset\EntrypointLookupInterface;

/**
 * Service worker отдаём динамически: подставляем актуальные (в проде — хешированные) URL ассетов
 * бандла 'app' из entrypoints в PRECACHE, чтобы калькуляторы /tools открывались офлайн «на холодную».
 * Имена ассетов меняются на деплое → меняются байты sw.js → SW сам обновляется и перекэширует свежее
 * (статический sw.js после деплоя держал бы мёртвые хеши). Путь /sw.js (корень) даёт корневой scope.
 */
#[Route('/sw.js', name: 'app_service_worker', methods: ['GET'])]
final class SwAction extends AbstractController
{
    public function __construct(
        private readonly EntrypointLookupInterface $entrypointLookup,
    ) {
    }

    public function __invoke(): Response
    {
        $this->entrypointLookup->reset();
        $assets = array_values(array_merge(
            $this->entrypointLookup->getJavaScriptFiles('app'),
            $this->entrypointLookup->getCssFiles('app'),
        ));

        // Identity-neutral и иммутабельное (хешированные ассеты, офлайн-страница, иконка) — cache-first.
        $cacheFirst = array_merge($assets, ['/offline.html', '/icons/android-chrome-192x192.png']);

        // Страницы /tools предкэшируем (офлайн-фолбэк), но отдаём network-first (залогиненный онлайн
        // видит свою оболочку), поэтому в cache-first наборе их НЕТ.
        $pages = [
            $this->generateUrl('app_tools_index'),
            $this->generateUrl('app_tools_mix'),
            $this->generateUrl('app_tools_film'),
            $this->generateUrl('app_tools_consumption'),
        ];

        $response = $this->render('sw.js.twig', [
            'precacheUrls' => array_values(array_merge($pages, $cacheFirst)),
            'cacheFirstUrls' => array_values($cacheFirst),
            // Версия кэша меняется вместе с хешами ассетов → activate чистит старый precache на деплое.
            'cacheVersion' => substr(sha1(implode('|', $assets)), 0, 12),
        ]);
        $response->headers->set('Content-Type', 'application/javascript');
        // Браузер должен видеть новую версию SW сразу после деплоя, а не из HTTP-кэша.
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Service-Worker-Allowed', '/');

        return $response;
    }
}
