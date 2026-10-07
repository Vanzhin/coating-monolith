<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Command\SaveRequirement;

use App\Shared\Application\Command\Command;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Сохранить требование: id (null → создать), имя, тип, должности, плоские позиции из формы.
 * Тип на создании; при обновлении менять тип нельзя (другой тип = другое требование).
 * templateUpload — новый/заменяющий Word-шаблон документа требования (null → не трогаем);
 * removeTemplate — снять шаблон (вернуться к дефолтной карточке). Команда синхронная (не сериализуется).
 *
 * @phpstan-type ItemInput array{label?: string, cadenceKind?: string, cadenceNumber?: string, amount?: string, unit?: string, basis?: string}
 */
readonly class SaveRequirementCommand extends Command
{
    /**
     * @param list<string>    $positionIds
     * @param list<ItemInput> $items
     */
    public function __construct(
        public ?string $id,
        public string $name,
        public string $type,
        public array $positionIds,
        public array $items,
        public ?UploadedFile $templateUpload = null,
        public bool $removeTemplate = false,
        public ?string $journalKind = null,
    ) {
    }
}
