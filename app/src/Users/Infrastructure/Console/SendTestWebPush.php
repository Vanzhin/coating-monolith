<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Console;

use App\Notifications\Application\UseCase\Command\SendNotification\SendNotificationCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Users\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ручная проверка уведомлений: создаёт уведомление пользователю через боевой путь
 * (SendNotificationCommand → Notification → доменное событие). Доставка в web push и бейдж —
 * асинхронно воркером (messenger:consume async, у нас manager_supervisor). Триггеров событий пока
 * нет — это способ «пнуть» доставку локально/на проде.
 */
#[AsCommand(name: 'app:push:test', description: 'Создать тестовое уведомление пользователю (доставка — асинхронно)')]
final class SendTestWebPush extends Command
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly CommandBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email пользователя')
            ->addArgument('message', InputArgument::OPTIONAL, 'Текст уведомления', 'Тестовое уведомление');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $message = (string) $input->getArgument('message');

        $user = $this->userRepository->getByEmail($email);
        if (null === $user) {
            $io->error(sprintf('Пользователь %s не найден.', $email));

            return Command::FAILURE;
        }

        $this->commandBus->execute(new SendNotificationCommand($user->getUlid(), $message));

        $io->success('Уведомление создано. Доставка в web push и бейдж — асинхронно (нужен запущенный messenger-воркер). Если пуш не пришёл — включи уведомления в браузере.');

        return Command::SUCCESS;
    }
}
