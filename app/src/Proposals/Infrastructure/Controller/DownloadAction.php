<?php
declare(strict_types=1);


namespace App\Proposals\Infrastructure\Controller;

use App\Proposals\Application\UseCase\Command\CreateProposalDocumentFile\CreateProposalDocumentFileCommand;
use App\Proposals\Domain\Aggregate\ProposalDocument\ProposalDocument;
use App\Proposals\Domain\Factory\ProposalDocumentFactory;
use App\Proposals\Domain\Repository\ProposalDocumentTemplateRepositoryInterface;
use App\Proposals\Domain\Service\GeneralProposalInfoFetcher;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Service\AssertService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route(path: '/cabinet/proposals/{proposalId}/download/{templateId}/{format}', name: 'app_cabinet_proposals_general_proposal_download')]
class DownloadAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface                         $commandBus,
        private readonly GeneralProposalInfoFetcher                  $generalProposalInfoFetcher,
        private readonly ProposalDocumentTemplateRepositoryInterface $proposalDocumentTemplateRepository,
        private readonly ProposalDocumentFactory                     $proposalDocumentFactory,
    )
    {
    }

    public function __invoke(Request $request, string $proposalId, string $templateId, string $format): Response
    {
        try {
            $template = $this->proposalDocumentTemplateRepository->findOneById($templateId);
            AssertService::notNull($template, 'Шаблон не найден.');
            $proposalInfo = $this->generalProposalInfoFetcher->getRequiredGeneralProposalInfo($proposalId);
            AssertService::notNull($proposalInfo, 'Форма не найдена.');
            AssertService::inArray(
                $format,
                $template->getAvailableFormats(),
                sprintf('Формат \'%s\' не поддерживается для шаблона.', $format));

            $document = $this->proposalDocumentFactory->create($template, $proposalInfo, $format);
            $command = new CreateProposalDocumentFileCommand($document);
            $result = $this->commandBus->execute($command);

            return $this->file($result->file, $this->buildFileName($document) . '.' . $result->file->getExtension());
        } catch (\Throwable $e) {
            $referer = $request->headers->get('referer');
            $this->addFlash('general_proposal_download_error', $e->getMessage());

            return $this->redirect($referer);
        }
    }

    private function buildFileName(ProposalDocument $document): string
    {
        $string = preg_replace('/[^\p{L}\p{N}\s]/u', '', $document->getProposalInfo()->getProjectTitle());

        return mb_substr($string, 0, 20) . '_' . (new \DateTimeImmutable())->format('Y-m-d_H-i-s');
    }

}