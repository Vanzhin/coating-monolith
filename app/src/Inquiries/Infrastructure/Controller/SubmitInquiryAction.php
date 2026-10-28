<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use App\Inquiries\Application\UseCase\Command\CreateInquiry\CreateInquiryCommand;
use App\Inquiries\Application\UseCase\Command\CreateInquiry\CreateInquiryCommandResult;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\File\StagedFileView;
use App\Shared\Application\File\StageFiles\StageFilesCommand;
use App\Shared\Application\File\StageFiles\StageFilesCommandResult;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Приём запроса из формы: файлы в Shared/File (stage), затем CreateInquiryCommand (пишет Received +
 * кидает в async). Тонкий: ловит \Exception → flash + назад на форму; успех → карточка «принято».
 */
#[Route(path: '/cabinet/inquiry', name: 'app_cabinet_inquiry_submit', methods: ['POST'])]
final class SubmitInquiryAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('inquiry', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Сессия устарела, попробуйте ещё раз.');

            return $this->redirectToRoute('app_cabinet_inquiry_new');
        }

        $text = trim((string) $request->request->get('text', ''));
        $files = array_values(array_filter(
            $request->files->all('attachments'),
            static fn (mixed $f): bool => $f instanceof UploadedFile,
        ));

        try {
            $fileIds = [];
            if ([] !== $files) {
                $staged = $this->commandBus->execute(new StageFilesCommand($this->uploaderId(), $files));
                \assert($staged instanceof StageFilesCommandResult);
                $fileIds = array_map(static fn (StagedFileView $v): string => $v->uuid, $staged->files);
            }

            $result = $this->commandBus->execute(new CreateInquiryCommand(InquiryType::SystemSelection, $text, $fileIds));
            \assert($result instanceof CreateInquiryCommandResult);
        } catch (\Exception $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_cabinet_inquiry_new');
        }

        $this->addFlash('success', 'Запрос принят, идёт подбор.');

        return $this->redirectToRoute('app_cabinet_inquiry_show', ['id' => $result->id]);
    }

    private function uploaderId(): string
    {
        $user = $this->getUser();

        return null !== $user ? $user->getUserIdentifier() : 'web';
    }
}
