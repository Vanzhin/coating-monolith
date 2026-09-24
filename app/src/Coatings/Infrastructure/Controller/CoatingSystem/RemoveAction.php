<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\CoatingSystem;

use App\Coatings\Application\UseCase\Command\RemoveCoatingSystem\RemoveCoatingSystemCommand;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Security\CsrfGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/cabinet/coating/coating-system/{id}/remove', name: 'app_cabinet_coating_system_remove', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
class RemoveAction extends AbstractController
{
    public function __construct(
        private readonly CommandBusInterface $commandBus,
        private readonly CsrfGuard $csrfGuard,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $this->csrfGuard->assertValid('delete', $request->request->getString('_token'));
        try {
            $this->commandBus->execute(new RemoveCoatingSystemCommand($id));
            $this->addFlash('coating_system_removed_success', 'Система покрытий удалена.');
        } catch (AppException $e) {
            $this->addFlash('coating_system_error', $e->getMessage());
        }

        return $this->redirectToRoute('app_cabinet_coating_system_list');
    }
}
