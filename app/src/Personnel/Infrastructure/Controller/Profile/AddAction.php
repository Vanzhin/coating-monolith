<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Profile;

use App\Personnel\Application\UseCase\Command\CreateProfile\CreateProfileCommand;
use App\Personnel\Domain\Aggregate\Profile\Gender;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Форма создания профиля. Пользователь платформы (userUlid) выбирается только здесь — привязка
 * не меняется на Update (см. UpdateProfileCommand: без userUlid, доменный инвариант "1 профиль на юзера"
 * живёт в UniqueUserProfileSpecification и просто перепроверился бы конструктором Profile).
 */
#[Route(
    path: '/cabinet/personnel/profile/create',
    name: 'app_cabinet_personnel_profile_create',
    methods: ['GET', 'POST'],
)]
final class AddAction extends AbstractController
{
    public function __construct(private readonly CommandBusInterface $commandBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $inputData = [];
        $error = null;
        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            try {
                $this->commandBus->execute(new CreateProfileCommand(
                    userUlid: (string) ($inputData['userUlid'] ?? ''),
                    lastName: (string) ($inputData['lastName'] ?? ''),
                    firstName: (string) ($inputData['firstName'] ?? ''),
                    middleName: (string) ($inputData['middleName'] ?? ''),
                    positionId: (string) ($inputData['positionId'] ?? ''),
                    organizationId: (string) ($inputData['organizationId'] ?? ''),
                    departmentId: (string) ($inputData['departmentId'] ?? ''),
                    personnelNumber: (string) ($inputData['personnelNumber'] ?? ''),
                    hiredAt: $this->nullableDate((string) ($inputData['hiredAt'] ?? '')),
                    clothing: (string) ($inputData['clothing'] ?? ''),
                    shoes: (string) ($inputData['shoes'] ?? ''),
                    headgear: (string) ($inputData['headgear'] ?? ''),
                    respirator: (string) ($inputData['respirator'] ?? ''),
                    gloves: (string) ($inputData['gloves'] ?? ''),
                    height: (string) ($inputData['height'] ?? ''),
                    gender: (string) ($inputData['gender'] ?? ''),
                ));
                $this->addFlash('profile_created_success', sprintf(
                    'Профиль «%s %s» добавлен.',
                    $inputData['lastName'] ?? '',
                    $inputData['firstName'] ?? '',
                ));

                return $this->redirectToRoute('app_cabinet_personnel_profile_list');
            } catch (AppException $e) {
                $error = $e->getMessage();
            }
        }

        return $this->render('admin/personnel/profile/form.html.twig', [
            'error' => $error,
            'inputData' => $inputData,
            'genders' => Gender::cases(),
        ]);
    }

    private function nullableDate(string $value): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new AppException('Некорректная дата приёма.');
        }
    }
}
