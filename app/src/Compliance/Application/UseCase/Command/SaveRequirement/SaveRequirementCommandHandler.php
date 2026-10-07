<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SaveRequirement;

use App\Compliance\Application\Service\AccessControl\ComplianceAccessControl;
use App\Compliance\Application\Service\RequirementItemBuilder;
use App\Compliance\Domain\Aggregate\Requirement\Requirement;
use App\Compliance\Domain\Event\RequirementChanged;
use App\Compliance\Domain\File\RequirementTemplatePurpose;
use App\Compliance\Domain\Repository\RequirementRepositoryInterface;
use App\Compliance\Domain\Type\ComplianceType;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Сохранить требование: создать (id=null) или обновить. Тип задаётся при создании и неизменяем — попытка
 * сменить тип на обновлении отклоняется (это было бы другое требование). Позиции собираются билдером под
 * тип; однотипность и дубли держит агрегат. Правила домена → AppException, ловится контроллером.
 */
final readonly class SaveRequirementCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private RequirementRepositoryInterface $repository,
        private RequirementItemBuilder $itemBuilder,
        private ComplianceAccessControl $access,
        private EventBusInterface $eventBus,
        private FileStorage $storage,
    ) {
    }

    public function __invoke(SaveRequirementCommand $command): SaveRequirementCommandResult
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $type = ComplianceType::tryFrom($command->type);
        if (null === $type) {
            throw new AppException('Выберите тип требования.');
        }

        $positions = new StringCollection(...array_values(array_unique(array_filter(
            $command->positionIds,
            static fn (string $id): bool => '' !== trim($id),
        ))));
        $items = $this->itemBuilder->build($type, $command->items);

        if (null === $command->id) {
            $requirement = new Requirement(Uuid::v7(), $command->name, $type, $positions, ...$items);
        } else {
            $requirement = $this->repository->findOneById($command->id);
            if (null === $requirement) {
                throw new AppException('Требование не найдено.', Response::HTTP_NOT_FOUND);
            }
            if ($requirement->getType() !== $type) {
                throw new AppException('Тип требования нельзя изменить. Создайте новое требование нужного типа.');
            }
            $requirement->rename($command->name);
            $requirement->replacePositions($positions);
            $requirement->replaceItems(...$items);
        }

        $this->applyTemplate($command, $requirement);

        $this->repository->add($requirement);
        $this->eventBus->execute(new RequirementChanged($requirement->getId()));

        return new SaveRequirementCommandResult($requirement->getId());
    }

    /**
     * Шаблон документа требования: снять (removeTemplate), заменить/задать (templateUpload) или не трогать.
     * owner файла = id требования (известен и для нового — Uuid::v7 в этом же вызове). Прежний шаблон
     * сносим перед заменой, чтобы не копить осиротевшие файлы.
     */
    private function applyTemplate(SaveRequirementCommand $command, Requirement $requirement): void
    {
        if ($command->removeTemplate) {
            $this->storage->removeByOwner(RequirementTemplatePurpose::Template, $requirement->getId());
            $requirement->setTemplateFileId(null);

            return;
        }
        if (null === $command->templateUpload) {
            return;
        }
        $this->storage->removeByOwner(RequirementTemplatePurpose::Template, $requirement->getId());
        $stored = $this->storage->store(RequirementTemplatePurpose::Template, $requirement->getId(), $command->templateUpload);
        $requirement->setTemplateFileId($stored->id());
    }
}
