<?php
declare(strict_types=1);
namespace App\ChemicalResistance\Domain\Aggregate\Assessment\Specification;

use App\ChemicalResistance\Domain\Repository\NoteRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;

final class AssessmentNotesConsistencyValidator
{
    public function __construct(private NoteRepositoryInterface $notes) {}

    public function validate(StringCollection $noteIds): void
    {
        $ids = $noteIds->getList();
        if (count($ids) !== count(array_unique($ids))) {
            throw new AppException('Список примечаний содержит дубли.');
        }
        $found = $this->notes->findAllByIds($ids);
        if (count($found) !== count($ids)) {
            throw new AppException('Один или несколько идентификаторов примечаний не найдены в справочнике.');
        }
    }
}
