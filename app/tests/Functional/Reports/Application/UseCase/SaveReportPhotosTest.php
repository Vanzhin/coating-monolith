<?php

declare(strict_types=1);

namespace App\Tests\Functional\Reports\Application\UseCase;

use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommand;
use App\Reports\Application\UseCase\Command\CreateReport\CreateReportCommandResult;
use App\Reports\Application\UseCase\Command\SaveReportContent\SaveReportContentCommand;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Domain\File\StoredFile;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\AuthenticatesActorTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Жизненный цикл фото при сохранении черновика: staged-файл привязывается к отчёту (promote),
 * убранное фото удаляется из хранилища, ссылка на несуществующий файл — ошибка.
 */
final class SaveReportPhotosTest extends KernelTestCase
{
    use AuthenticatesActorTrait;

    private CommandBusInterface $commandBus;
    private ReportRepositoryInterface $reports;
    private FileStorage $storage;
    private EntityManagerInterface $em;
    /** @var list<string> */
    private array $reportIds = [];
    /** @var list<string> */
    private array $fileIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $c = static::getContainer();
        $this->commandBus = $c->get(CommandBusInterface::class);
        $this->reports = $c->get(ReportRepositoryInterface::class);
        $this->storage = $c->get(FileStorage::class);
        $this->em = $c->get(EntityManagerInterface::class);
        $this->authenticateAsSystem();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        try {
            foreach ($this->fileIds as $id) {
                $this->storage->remove($id);
            }
            foreach ($this->reportIds as $id) {
                if (null !== ($r = $this->reports->findOneById($id))) {
                    $this->reports->remove($r);
                }
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'tearDown cleanup error: '.$e->getMessage()."\n");
        }
        parent::tearDown();
    }

    public function test_staged_photo_is_promoted_and_bound_to_report(): void
    {
        $reportId = $this->createReport();
        $uuid = $this->stagePhoto();
        self::assertSame(StoredFile::STATUS_STAGED, $this->storage->get($uuid)?->status());

        $this->commandBus->execute(new SaveReportContentCommand($reportId, [
            'photos' => ['items' => [['file' => $uuid, 'caption' => 'Общий вид']]],
        ]));
        $this->em->clear();

        $file = $this->storage->get($uuid);
        self::assertNotNull($file);
        self::assertSame(StoredFile::STATUS_STORED, $file->status());
        self::assertStringContainsString($reportId, $file->storageKey()); // привязан к отчёту (reports/photo/{reportId}/…)
    }

    public function test_detached_photo_is_removed_on_next_save(): void
    {
        $reportId = $this->createReport();
        $uuid = $this->stagePhoto();

        $this->commandBus->execute(new SaveReportContentCommand($reportId, [
            'photos' => ['items' => [['file' => $uuid]]],
        ]));
        $this->em->clear();
        self::assertNotNull($this->storage->get($uuid));

        // Сохраняем без этого фото — оно должно уйти из хранилища.
        $this->commandBus->execute(new SaveReportContentCommand($reportId, ['photos' => ['items' => []]]));
        $this->em->clear();

        self::assertNull($this->storage->get($uuid));
    }

    public function test_reference_to_missing_file_throws(): void
    {
        $reportId = $this->createReport();

        $this->expectException(AppException::class);
        $this->commandBus->execute(new SaveReportContentCommand($reportId, [
            'photos' => ['items' => [['file' => '00000000-0000-0000-0000-000000000000']]],
        ]));
    }

    private function createReport(): string
    {
        $result = $this->commandBus->execute(new CreateReportCommand(ReportType::TrialApplication));
        \assert($result instanceof CreateReportCommandResult);
        $this->reportIds[] = $result->id;

        return $result->id;
    }

    private function stagePhoto(): string
    {
        $staged = $this->storage->stage('user-photo-test', $this->upload($this->pngBytes(), 'photo.png'));
        $this->fileIds[] = $staged->id();

        return $staged->id();
    }

    private function pngBytes(int $width = 2, int $height = 2): string
    {
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    private function upload(string $bytes, string $name): UploadedFile
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'rep_photo_');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }
}
