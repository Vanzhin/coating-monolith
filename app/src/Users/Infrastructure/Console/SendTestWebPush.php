<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Console;

use App\Shared\Infrastructure\Exception\AppException;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Domain\Entity\ValueObject\PushSubscription;
use App\Users\Domain\Repository\ChannelRepositoryInterface;
use App\Users\Domain\Repository\UserRepositoryInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ручная проверка доставки web push: шлёт тестовое уведомление во все WEB_PUSH-каналы юзера
 * и печатает отчёт FCM по каждой подписке (success/status/reason). Триггеров событий пока нет —
 * это единственный способ «пнуть» подписку локально и увидеть, что вернул push-сервис.
 */
#[AsCommand(name: 'app:push:test', description: 'Отправить тестовый web push всем подпискам пользователя')]
final class SendTestWebPush extends Command
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly ChannelRepositoryInterface $channelRepository,
        private readonly string $vapidPublicKey,
        private readonly string $vapidPrivateKey,
        private readonly string $vapidSubject,
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

        $channels = $this->channelRepository->findByOwnerAndType($user->getUlid(), ChannelType::WEB_PUSH->value);
        if ([] === $channels) {
            $io->warning('У пользователя нет web-push подписок. Включи уведомления в браузере и повтори.');

            return Command::SUCCESS;
        }

        $webPush = new WebPush(['VAPID' => [
            'subject' => $this->vapidSubject,
            'publicKey' => $this->vapidPublicKey,
            'privateKey' => $this->vapidPrivateKey,
        ]]);
        $payload = json_encode(['title' => 'Уведомление', 'body' => $message], JSON_UNESCAPED_UNICODE);

        $rows = [];
        foreach ($channels as $channel) {
            try {
                $subscription = PushSubscription::fromJson($channel->getValue());
            } catch (AppException) {
                $rows[] = ['—', 'битый JSON', '', ''];

                continue;
            }
            $report = $webPush->sendOneNotification(Subscription::create($subscription->jsonSerialize()), false === $payload ? null : $payload);
            $response = $report->getResponse();
            $expired = $report->isSubscriptionExpired();
            if ($expired) {
                // Мёртвую подписку чистим — как боевой WebPushNotifier.
                $this->channelRepository->remove($channel);
            }
            $rows[] = [
                substr($subscription->endpoint, -24),
                $report->isSuccess() ? 'OK' : ($expired ? 'протухла (удалена)' : 'ОТКАЗ'),
                null !== $response ? (string) $response->getStatusCode() : '',
                $report->isSuccess() ? '' : substr($report->getReason(), 0, 60),
            ];
        }

        $io->table(['endpoint (хвост)', 'итог', 'HTTP', 'причина'], $rows);
        $io->success('Готово. success = FCM принял пуш; дальше показ зависит от ОС/браузера.');

        return Command::SUCCESS;
    }
}
