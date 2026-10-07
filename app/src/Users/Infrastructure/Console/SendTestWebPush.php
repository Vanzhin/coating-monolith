<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Console;

use App\Notifications\Domain\Event\UserActivatedNotification;
use App\Shared\Application\Event\EventBusInterface;
use App\Users\Domain\Repository\UserRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ручная проверка доставки уведомлений через боевой путь: публикует системное уведомляющее событие
 * (UserActivatedNotification, Owner=указанный юзер) → единый NotificationDispatcher доставляет в inbox
 * и web push в воркере (messenger:consume async, у нас manager_supervisor). Способ «пнуть» доставку локально/на проде.
 */
#[AsCommand(name: 'app:push:test', description: 'Пнуть доставку уведомления пользователю через диспетчер (async)')]
final class SendTestWebPush extends Command
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly EventBusInterface $eventBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email пользователя-адресата');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $user = $this->userRepository->getByEmail($email);
        if (null === $user) {
            $io->error(sprintf('Пользователь %s не найден.', $email));

            return Command::FAILURE;
        }

        $this->eventBus->execute(new UserActivatedNotification($user->getUlid(), $user->getEmail()->getValue()));

        $io->success('Событие опубликовано. Доставка в inbox и web push — асинхронно (нужен запущенный messenger-воркер). Если пуш не пришёл — включи уведомления в браузере.');

        return Command::SUCCESS;
    }
}
