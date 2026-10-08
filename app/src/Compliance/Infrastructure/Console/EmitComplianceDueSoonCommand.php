<?php

declare(strict_types=1);

namespace App\Compliance\Infrastructure\Console;

use App\Compliance\Domain\Event\ComplianceDueSoon;
use App\Notifications\Domain\Event\ComplianceDueItem;
use App\Notifications\Domain\Event\DueKind;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ручной триггер пилота уведомлений (Фаза 1): публикует событие «подходит срок выдачи СИЗ» по сотруднику.
 * ФИО берём из профиля, позицию и срок — аргументами. Автоматический проходчик (скан nextDueAt) — Фаза 2.
 */
#[AsCommand(name: 'app:compliance:emit-due-soon', description: 'Опубликовать уведомление «подходит срок выдачи СИЗ» по сотруднику.')]
final class EmitComplianceDueSoonCommand extends Command
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly EventBusInterface $eventBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('profileId', InputArgument::REQUIRED, 'profileId сотрудника')
            ->addArgument('obligationLabel', InputArgument::REQUIRED, 'Наименование позиции (СИЗ)')
            ->addArgument('dueDate', InputArgument::REQUIRED, 'Срок (как показывать, напр. 05.12.2026)')
            ->addArgument('kind', InputArgument::OPTIONAL, 'soon|overdue (по умолчанию soon)', 'soon');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $profileId = (string) $input->getArgument('profileId');

        /** @var GetProfileQueryResult $result */
        $result = $this->queryBus->execute(new GetProfileQuery($profileId));
        if (null === $result->profile) {
            $output->writeln('<error>Профиль не найден: '.$profileId.'</error>');

            return Command::FAILURE;
        }
        $fio = trim(sprintf('%s %s %s', $result->profile->lastName, $result->profile->firstName, $result->profile->middleName ?? ''));

        $kind = DueKind::tryFrom((string) $input->getArgument('kind')) ?? DueKind::Soon;
        $this->eventBus->execute(new ComplianceDueSoon(
            $profileId,
            $fio,
            new ComplianceDueItem(
                (string) $input->getArgument('obligationLabel'),
                $kind,
                (string) $input->getArgument('dueDate'),
            ),
        ));

        $output->writeln('Событие опубликовано. Доставка — асинхронно (нужен запущенный messenger-воркер).');

        return Command::SUCCESS;
    }
}
