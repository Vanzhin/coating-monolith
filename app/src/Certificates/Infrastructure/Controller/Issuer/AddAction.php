<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Controller\Issuer;

use App\Certificates\Application\UseCase\Command\CreateIssuer\CreateIssuerCommand;
use App\Shared\Application\Command\CommandBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/certificate/issuer/create',
    name: 'app_cabinet_certificate_issuer_create',
    methods: ['GET', 'POST'],
)]
final class AddAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $inputData = [];
        $error = null;
        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            try {
                $this->commandBus->execute(new CreateIssuerCommand((string) ($inputData['title'] ?? '')));
                $this->addFlash('issuer_created_success', sprintf('Организация «%s» добавлена.', $inputData['title'] ?? ''));

                return $this->redirectToRoute('app_cabinet_certificate_issuer_list');
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('admin/certificate/issuer/form.html.twig', compact('error', 'inputData'));
    }
}
