<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Event;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Event\EventInterface;

/**
 * Черновик карточки выдачи удалён → производное (пересчёт проекции этого человека по этому требованию +
 * черновик выдачи на оставшийся дефицит) делаем в воркере, идемпотентно — удаление не должно оставлять
 * человека с дефицитом без карточки.
 *
 * $fileIdsToRemove — сканы удалённых актов при админском сносе подписанного документа: воркер чистит их
 * post-commit (откат транзакции команды не осиротит ссылку на уже стёртый файл). У обычного удаления черновика
 * файлов нет → null.
 */
final readonly class DraftDeleted implements EventInterface
{
    public function __construct(
        public string $profileId,
        public string $requirementId,
        public ?StringCollection $fileIdsToRemove = null,
    ) {
    }
}
