<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\Channel;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Helper\ExceptionHelperTrait;
use App\Users\Application\DTO\Channel\ChannelDTO;
use App\Users\Application\UseCase\Command\CreateChannel\CreateChannelCommand;
use App\Users\Domain\Entity\User;
use App\Users\Infrastructure\Form\CreateChannelFormType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('user/channel/create', name: 'app_user_channel_create', methods: ['GET', 'POST'])]
class CreateChannelAction extends AbstractController
{
    use ExceptionHelperTrait;

    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();
        assert($user instanceof User);

        if (!$user->isActive()) {
            return $this->redirectToRoute('app_user_channel_verification');
        }

        // Получаем значения из query параметров
        $type = $request->query->get('type');
        $value = $request->query->get('value');

        // Подготавливаем начальные данные для формы
        $formData = [];
        if ($type) {
            $formData['type'] = $type;
        }
        if ($value) {
            $formData['value'] = $value;
        }

        $form = $this->createForm(CreateChannelFormType::class, $formData);
        // Query-параметры лишь ПРЕДЗАПОЛНЯЮТ форму; создание канала — только POST через форму с
        // нативным Symfony-CSRF. Прежняя ветка авто-создания на GET «минуя CSRF» удалена (CSRF-вектор).
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                return $this->createChannelFromFormData($form->getData(), $user);
            } catch (\Exception $e) {
                $this->handleChannelCreationException($e);
            }
        }

        return $this->render('user/channel/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createChannelFromFormData(array $data, User $user): Response
    {
        $channelDto = new ChannelDTO(
            id: UuidService::generate(),
            type: $data['type'],
            value: $data['value'],
            owner_id: $user->getId(),
        );

        $command = new CreateChannelCommand($channelDto);
        $this->commandBus->execute($command);

        $this->addFlash('success', 'Канал успешно создан! Теперь вы можете верифицировать его.');

        return $this->redirectToRoute('app_user_channel_verification');
    }

    /**
     * Обрабатывает исключения при создании канала.
     */
    private function handleChannelCreationException(\Exception $e): void
    {
        if ($e instanceof UniqueConstraintViolationException) {
            // Обработка нарушения уникального ограничения
            $this->addFlash('error', 'Канал с таким типом и значением уже существует.');

            return;
        }

        if ($e instanceof HandlerFailedException) {
            // Проверяем вложенные исключения в Messenger
            if ($this->hasUniqueConstraintViolation($e)) {
                $this->addFlash('error', 'Канал с таким типом и значением уже существует.');

                return;
            }
        }

        // Доменную ошибку (человекочитаемую) показываем как есть; инфраструктурную — в лог,
        // пользователю нейтрально, без утечки внутренностей наружу.
        $original = $this->getOriginalException($e);
        if ($original instanceof AppException) {
            $this->addFlash('error', $original->getMessage());

            return;
        }

        $this->logger->error('Не удалось создать канал', ['exception' => $e]);
        $this->addFlash('error', 'Не удалось создать канал. Попробуйте позже.');
    }

    /**
     * Проверяет, есть ли в цепочке исключений UniqueConstraintViolationException.
     */
    private function hasUniqueConstraintViolation(\Throwable $e): bool
    {
        if ($e instanceof UniqueConstraintViolationException) {
            return true;
        }

        if ($e instanceof HandlerFailedException) {
            foreach ($e->getWrappedExceptions() as $nested) {
                if ($this->hasUniqueConstraintViolation($nested)) {
                    return true;
                }
            }
        }

        $previous = $e->getPrevious();
        if (null !== $previous) {
            return $this->hasUniqueConstraintViolation($previous);
        }

        return false;
    }
}
