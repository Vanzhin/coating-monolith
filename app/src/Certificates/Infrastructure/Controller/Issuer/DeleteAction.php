<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Controller\Issuer;

use App\Certificates\Application\UseCase\Command\DeleteIssuer\DeleteIssuerCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/certificate/issuer/{id}/delete',
    name: 'app_cabinet_certificate_issuer_delete',
)]
final class DeleteAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(string $id): Response
    {
        try {
            $this->commandBus->execute(new DeleteIssuerCommand($id));
            $this->addFlash('issuer_removed_success', 'Организация удалена.');
        } catch (\Exception|\Error $e) {
            $this->addFlash('issuer_removed_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_certificate_issuer_list');
    }
}
