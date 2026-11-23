<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Controller\Profile;

use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Waybills\Application\UseCase\Command\CreateDriverProfile\CreateDriverProfileCommand;
use App\Waybills\Application\UseCase\Command\UpdateDriverProfile\UpdateDriverProfileCommand;
use App\Waybills\Application\UseCase\Query\GetOwnDriverProfile\GetOwnDriverProfileQuery;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\SettlementType;
use App\Waybills\Infrastructure\Mapper\DriverProfileFormMapper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

final class DriverProfileController extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queries, private readonly CommandBusInterface $commands, private readonly DriverProfileFormMapper $mapper)
    {
    }

    #[Route('/cabinet/waybills/profile', name: 'app_cabinet_waybills_profile', methods: ['GET'])]
    public function show(): Response
    {
        $profile = $this->queries->execute(new GetOwnDriverProfileQuery());

        return $this->render('cabinet/waybill/profile/show.html.twig', ['profile' => $profile]);
    }

    #[Route('/cabinet/waybills/profile/edit', name: 'app_cabinet_waybills_profile_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $profile = $this->queries->execute(new GetOwnDriverProfileQuery());
        $data = $this->mapper->fromProfile($profile);
        $error = null;
        $status = Response::HTTP_OK;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('waybills_driver_profile', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Недействительный CSRF-токен.');
            }
            $data = $request->request->all();
            $data['destinations'] ??= [];
            $action = $request->request->getString('action', 'save');
            if ('add' === $action) {
                $data['destinations'][] = ['location' => ['country' => Location::DEFAULT_COUNTRY], 'distance' => '', 'enterprise' => ''];
            } elseif (str_starts_with($action, 'remove:')) {
                unset($data['destinations'][substr($action, 7)]);
            } else {
                try {
                    [$details, $route] = $this->mapper->toDomain($data, $profile);
                    $command = null === $profile->id
                        ? new CreateDriverProfileCommand($details, $route)
                        : new UpdateDriverProfileCommand(Uuid::fromString($profile->id), $details, $route);
                    $this->commands->execute($command);

                    return $this->redirectToRoute('app_cabinet_waybills_profile');
                } catch (\Exception $exception) {
                    $error = $exception->getMessage();
                    $status = Response::HTTP_UNPROCESSABLE_ENTITY;
                }
            }
        }

        return $this->render('cabinet/waybill/profile/form.html.twig', ['data' => $data, 'profile' => $profile, 'error' => $error, 'settlementTypes' => SettlementType::cases()], new Response(status: $status));
    }
}
