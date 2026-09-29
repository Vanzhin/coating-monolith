<?php

declare(strict_types=1);

namespace App\Personnel\Infrastructure\Controller\Profile;

use App\Personnel\Application\UseCase\Command\UpdateProfile\UpdateProfileCommand;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQuery;
use App\Personnel\Application\UseCase\Query\GetProfile\GetProfileQueryResult;
use App\Personnel\Domain\Aggregate\Profile\Gender;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Форма правки профиля. userUlid НЕ редактируется (UpdateProfileCommand его не принимает) —
 * привязка к учётной записи фиксируется на создании, форма её не показывает и не шлёт.
 */
#[Route(
    path: '/cabinet/personnel/profile/{id}/edit',
    name: 'app_cabinet_personnel_profile_update',
    methods: ['GET', 'POST'],
)]
final class UpdateAction extends AbstractController
{
    public function __construct(
        private readonly QueryBusInterface $queryBus,
        private readonly CommandBusInterface $commandBus,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $result = $this->queryBus->execute(new GetProfileQuery($id));
        \assert($result instanceof GetProfileQueryResult);
        if (null === $result->profile) {
            $this->addFlash('profile_updated_error', 'Профиль не найден.');

            return $this->redirectToRoute('app_cabinet_personnel_profile_list');
        }

        $error = null;
        if ($request->isMethod(Request::METHOD_POST)) {
            $inputData = $request->getPayload()->all();
            $inputData['id'] = $id;
            try {
                $this->commandBus->execute(new UpdateProfileCommand(
                    id: $id,
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
                    gasMask: (string) ($inputData['gasMask'] ?? ''),
                    respirator: (string) ($inputData['respirator'] ?? ''),
                    gloves: (string) ($inputData['gloves'] ?? ''),
                    height: (string) ($inputData['height'] ?? ''),
                    gender: (string) ($inputData['gender'] ?? ''),
                ));
                $this->addFlash('profile_updated_success', 'Профиль обновлён.');

                return $this->redirectToRoute('app_cabinet_personnel_profile_list');
            } catch (AppException $e) {
                $error = $e->getMessage();
            }
        } else {
            $profile = $result->profile;
            $inputData = [
                'id' => $id,
                'userUlid' => $profile->userUlid,
                'lastName' => $profile->lastName,
                'firstName' => $profile->firstName,
                'middleName' => $profile->middleName,
                'positionId' => $profile->positionId,
                'positionTitle' => $profile->positionTitle,
                'organizationId' => $profile->organizationId,
                'organizationTitle' => $profile->organizationTitle,
                'departmentId' => $profile->departmentId,
                'departmentTitle' => $profile->departmentTitle,
                'personnelNumber' => $profile->personnelNumber,
                'hiredAt' => $profile->hiredAt?->format('Y-m-d'),
                'clothing' => $profile->clothing,
                'shoes' => $profile->shoes,
                'headgear' => $profile->headgear,
                'gasMask' => $profile->gasMask,
                'respirator' => $profile->respirator,
                'gloves' => $profile->gloves,
                'height' => $profile->height,
                'gender' => $profile->gender,
            ];
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
