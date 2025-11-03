<?php

declare(strict_types=1);

namespace App\Users\Infrastructure\Controller\Channel;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Helper\ExceptionHelperTrait;
use App\Users\Application\DTO\Channel\ChannelDTO;
use App\Users\Application\UseCase\Command\CreateChannel\CreateChannelCommand;
use App\Users\Domain\Entity\ChannelType;
use App\Users\Infrastructure\Form\CreateChannelFormType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('user/channel/create', name: 'app_user_channel_create', methods: ['GET', 'POST'])]
class CreateChannelAction extends AbstractController
{
    use ExceptionHelperTrait;

    public function __construct(
        private readonly CommandBusInterface $commandBus
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $this->getUser();

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

        // Если есть данные из query параметров, проверяем валидность и автоматически создаем канал
        if (!empty($formData) && $type && $value && !$request->isMethod('POST')) {
            // Валидируем данные вручную, минуя CSRF
            $isValid = true;
            $errors = [];
            
            // Проверяем тип канала
            if (!in_array($type, [ChannelType::EMAIL->value, ChannelType::TELEGRAM->value], true)) {
                $isValid = false;
                $errors[] = 'Неверный тип канала';
            }
            
            // Проверяем значение
            if (empty($value) || strlen($value) < 3 || strlen($value) > 255) {
                $isValid = false;
                $errors[] = 'Значение канала должно содержать от 3 до 255 символов';
            }
            
            // Если тип email - проверяем формат email
            if ($isValid && $type === ChannelType::EMAIL->value) {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $isValid = false;
                    $errors[] = 'Неверный формат email адреса';
                }
            }
            
            // Если данные валидны, создаем канал напрямую, минуя форму и CSRF
            if ($isValid) {
                try {
                    return $this->createChannelFromFormData($formData, $user);
                } catch (\Exception $e) {
                    $this->handleChannelCreationException($e);
                }
            } else {
                // Показываем ошибки валидации
                foreach ($errors as $error) {
                    $this->addFlash('error', $error);
                }
            }
        }

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

    private function createChannelFromFormData(array $data, $user): Response
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
     * Обрабатывает исключения при создании канала
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

        // Общая обработка ошибок
        $this->addFlash('error', $this->getOriginalExceptionMessage($e));
    }

    /**
     * Проверяет, есть ли в цепочке исключений UniqueConstraintViolationException
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
        if ($previous !== null) {
            return $this->hasUniqueConstraintViolation($previous);
        }

        return false;
    }
}

