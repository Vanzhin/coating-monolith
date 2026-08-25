<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Domain\Service\SystemCertificate;
use App\Coatings\Domain\Service\SystemCertificatesGateway;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Ленивая подгрузка документов системы (для превью-модалки). Читает Certificates через
 * порт Coatings→Certificates; в список систем не встраивается (там только счётчик).
 */
#[Route(
    path: '/cabinet/coating/coating-system/{id}/documents',
    name: 'app_cabinet_coating_system_documents',
    methods: ['GET'],
)]
final class DocumentsAction extends AbstractController
{
    public function __construct(private readonly SystemCertificatesGateway $gateway)
    {
    }

    public function __invoke(string $id): Response
    {
        $items = array_map(
            static fn (SystemCertificate $c): array => [
                'id' => $c->id,
                'title' => $c->title,
                'kindLabel' => $c->kindLabel,
                'issuerTitle' => $c->issuerTitle,
                'issuedAt' => $c->issuedAt->format('d.m.Y'),
                'expiresAt' => $c->expiresAt?->format('d.m.Y'),
                'isExpired' => $c->isExpired,
                'testStandard' => $c->testStandard,
                'downloadUrl' => $c->downloadUrl,
            ],
            $this->gateway->listBySystem($id),
        );

        return new JsonResponse(['items' => $items]);
    }
}
