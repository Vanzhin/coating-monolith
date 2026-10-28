<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use App\Inquiries\Application\DTO\View\InquiryEditFormView;
use App\Inquiries\Application\UseCase\Query\GetInquiryEditForm\GetInquiryEditFormQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ленивый фрагмент-модалка с формой правки распознанных параметров. Грузится по клику
 * (openFragmentModal); сабмит — синхронный переподбор через ReprocessInquiryAction.
 */
#[Route(path: '/cabinet/inquiry/{id}/edit', name: 'app_cabinet_inquiry_edit', methods: ['GET'])]
final class EditInquiryModalAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(string $id): Response
    {
        $form = $this->queryBus->execute(new GetInquiryEditFormQuery($id));
        if (!$form instanceof InquiryEditFormView) {
            throw $this->createNotFoundException('Запрос не найден.');
        }

        return $this->render('cabinet/inquiry/_edit_modal.html.twig', ['form' => $form]);
    }
}
