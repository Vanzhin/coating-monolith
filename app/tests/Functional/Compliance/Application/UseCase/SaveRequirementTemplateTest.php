<?php

declare(strict_types=1);

namespace App\Tests\Functional\Compliance\Application\UseCase;

use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommand;
use App\Compliance\Application\UseCase\Command\SaveRequirement\SaveRequirementCommandResult;
use App\Compliance\Domain\File\RequirementTemplatePurpose;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\File\FileStorage;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Шаблон документа требования: загрузка задаёт templateFileId + кладёт файл в реестр; повторная загрузка
 * заменяет (прежний сносится); removeTemplate снимает (файла и id нет). Шаблон — реальный docx (пройдёт mime).
 */
final class SaveRequirementTemplateTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    public function test_store_replace_remove_requirement_template(): void
    {
        self::bootKernel();
        $this->authenticateAsSystem();
        $c = self::getContainer();
        $bus = $c->get(CommandBusInterface::class);
        $repo = $c->get(RequirementRepositoryInterface::class);
        $storage = $c->get(FileStorage::class);
        $em = $c->get(EntityManagerInterface::class);

        // Создать не материальное требование со своим шаблоном.
        $result = $bus->execute($this->save(null, templateUpload: $this->docxUpload()));
        self::assertInstanceOf(SaveRequirementCommandResult::class, $result);
        $id = $result->id;

        $em->clear();
        $req = $repo->findOneById($id);
        self::assertNotNull($req);
        $firstFileId = $req->getTemplateFileId();
        self::assertNotNull($firstFileId, 'шаблон задан');
        self::assertCount(1, $storage->byOwner(RequirementTemplatePurpose::Template, $id));

        // Заменить шаблон — прежний уходит, остаётся один.
        $bus->execute($this->save($id, templateUpload: $this->docxUpload()));
        $em->clear();
        $req = $repo->findOneById($id);
        self::assertNotNull($req->getTemplateFileId());
        self::assertNotSame($firstFileId, $req->getTemplateFileId(), 'шаблон заменён');
        self::assertCount(1, $storage->byOwner(RequirementTemplatePurpose::Template, $id), 'прежний снесён');

        // Снять шаблон.
        $bus->execute($this->save($id, removeTemplate: true));
        $em->clear();
        $req = $repo->findOneById($id);
        self::assertNull($req->getTemplateFileId(), 'шаблон снят');
        self::assertCount(0, $storage->byOwner(RequirementTemplatePurpose::Template, $id));
    }

    private function save(?string $id, ?UploadedFile $templateUpload = null, bool $removeTemplate = false): SaveRequirementCommand
    {
        return new SaveRequirementCommand(
            $id,
            'Противопожарный инструктаж',
            'non_material',
            ['pos-1'],
            [['label' => 'Повторный инструктаж', 'cadenceKind' => 'periodic', 'cadenceNumber' => '6', 'cadenceUnit' => 'month', 'basis' => 'ГОСТ 12.0.004']],
            $templateUpload,
            $removeTemplate,
        );
    }

    private function docxUpload(): UploadedFile
    {
        $src = self::getContainer()->getParameter('kernel.project_dir').'/src/Compliance/Infrastructure/Resources/templates/requirement_card.docx';
        $tmp = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        copy($src, $tmp);

        return new UploadedFile($tmp, 'journal.docx', null, null, true);
    }
}
