<?php
declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\CreateProposalDocumentFile;

use App\Proposals\Application\Service\AccessControl\GeneralProposalInfoAccessControl;
use App\Proposals\Application\Service\Handler\GenerateCommercialProposalXlsx;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\AssertService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\File\File;

readonly class CreateProposalDocumentFileCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private GeneralProposalInfoAccessControl $generalProposalInfoAccessControl,
        private GenerateCommercialProposalXlsx   $generateGeneralProposalXlsx,
    )
    {
    }

    public function __invoke(CreateProposalDocumentFileCommand $command): CreateProposalDocumentFileCommandResult
    {
        AssertService::true(
            $this->generalProposalInfoAccessControl->canUpdateGeneralProposalInfo(
                $command->document->getProposalInfo()->getOwnerId(),
                $command->document->getProposalInfo()->getId()
            ),
            'Запрещено.'
        );
        //todo написать логику заполнения шаблона
        //todo разобраться с путями
        /** Load $inputFileName to a Spreadsheet object **/
        $spreadsheet = $this->generateGeneralProposalXlsx->generate($command->document);

        $writer = IOFactory::createWriter($spreadsheet, 'Tcpdf');

        $writer->save('/app/src/Proposals/Infrastructure/Resources/proposals/temp/test.pdf');

        return new CreateProposalDocumentFileCommandResult(new File('/app/src/Proposals/Infrastructure/Resources/proposals/temp/test.pdf'));
    }
}