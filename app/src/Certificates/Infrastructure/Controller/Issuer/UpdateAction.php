<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Controller\Issuer;

use App\Certificates\Application\UseCase\Command\UpdateIssuer\UpdateIssuerCommand;
use App\Certificates\Application\UseCase\Query\GetIssuer\GetIssuerQuery;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/certificate/issuer/{id}/edit',
    name: 'app_cabinet_certificate_issuer_update',
    methods: ['GET', 'POST'],
)]
#[IsGranted('ROLE_ADMIN')]
final class UpdateAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $result = $this->queryBus->execute(new GetIssuerQuery($id));
        if (null === $result->issuer) {
            $this->addFlash('issuer_updated_error', sprintf('Издатель «%s» не найден.', $id));

            return $this->redirectToRoute('app_cabinet_certificate_issuer_list');
        }

        $error = null;
        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            $inputData['id'] = $id;
            try {
                $this->commandBus->execute(new UpdateIssuerCommand($id, (string) ($inputData['title'] ?? '')));
                $this->addFlash('issuer_updated_success', sprintf('Издатель «%s» обновлён.', $inputData['title'] ?? ''));

                return $this->redirectToRoute('app_cabinet_certificate_issuer_list');
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
            }
        } else {
            $inputData = ['id' => $id, 'title' => $result->issuer->title];
        }

        return $this->render('admin/certificate/issuer/form.html.twig', compact('error', 'inputData'));
    }
}
