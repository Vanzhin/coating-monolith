<?php

declare(strict_types=1);

namespace App\Certificates\Infrastructure\Controller\Document;

use App\Certificates\Application\UseCase\Query\GetDocument\GetDocumentQuery;
use App\Certificates\Application\UseCase\Query\GetPagedIssuers\GetPagedIssuersQuery;
use App\Certificates\Domain\Aggregate\Document\DocumentKind;
use App\Certificates\Domain\Aggregate\Document\ReferenceType;
use App\Certificates\Domain\Repository\IssuersFilter;
use App\Certificates\Infrastructure\Mapper\DocumentMapper;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(
    path: '/cabinet/certificate/document/{id}/edit',
    name: 'app_cabinet_certificate_document_update',
    methods: ['GET', 'POST'],
)]
final class UpdateAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly QueryBusInterface $queryBus,
        private readonly DocumentMapper $mapper,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $result = $this->queryBus->execute(new GetDocumentQuery($id));
        if (null === $result->document) {
            $this->addFlash('document_updated_error', 'Документ не найден.');

            return $this->redirectToRoute('app_cabinet_certificate_document_list');
        }

        $error = null;
        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->request->all();
            $inputData['id'] = $id;
            $file = $request->files->get('file');
            try {
                $command = $this->mapper->buildUpdateCommand($id, $inputData, $file instanceof UploadedFile ? $file : null);
                $this->commandBus->execute($command);
                $this->addFlash('document_updated_success', 'Документ обновлён.');

                return $this->redirectToRoute('app_cabinet_certificate_document_list');
            } catch (\Exception|\Error $e) {
                $error = $e->getMessage();
            }
        } else {
            $inputData = $this->mapper->buildInputDataFromDto($result->document);
        }

        return $this->render('admin/certificate/document/form.html.twig', $this->viewData($inputData, $error));
    }

    /**
     * @param array<string, mixed> $inputData
     *
     * @return array<string, mixed>
     */
    private function viewData(array $inputData, ?string $error): array
    {
        $issuers = $this->queryBus->execute(new GetPagedIssuersQuery(new IssuersFilter(pager: Pager::fromPage(1, 1000))));

        return [
            'inputData' => $inputData,
            'error' => $error,
            'issuers' => $issuers->issuers,
            'kinds' => DocumentKind::cases(),
            'referenceTypes' => ReferenceType::cases(),
        ];
    }
}
