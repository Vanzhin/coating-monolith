<?php
declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\CreateProposalDocumentFile;

use App\Proposals\Application\Service\AccessControl\GeneralProposalInfoAccessControl;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\AssertService;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\File\File;

readonly class CreateProposalDocumentFileCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private GeneralProposalInfoAccessControl $generalProposalInfoAccessControl,
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
        $inputFileName = '/app/src/Proposals/Infrastructure/Resources/proposals/templates/tkp_template.xlsx';
//        dd($inputFileName);
        /** Load $inputFileName to a Spreadsheet object **/
        $spreadsheet = IOFactory::load($inputFileName);
        $file = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $file->save('/app/src/Proposals/Infrastructure/Resources/proposals/temp/test.xlsx');

        return new CreateProposalDocumentFileCommandResult(new File('/app/src/Proposals/Infrastructure/Resources/proposals/temp/test.xlsx'));
    }
}