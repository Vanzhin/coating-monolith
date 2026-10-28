<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use App\Inquiries\Application\DTO\View\InquiryFullText;
use App\Inquiries\Application\UseCase\Query\GetInquiryFullText\GetInquiryFullTextQuery;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Ленивый фрагмент-модалка с полным текстом запроса (тело + извлечённый текст вложений). Грузится
 * по клику «Посмотреть текст целиком» (openFragmentModal), чтобы не тащить блоб в карточку.
 */
#[Route(path: '/cabinet/inquiry/{id}/text', name: 'app_cabinet_inquiry_text', methods: ['GET'])]
final class InquiryTextAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(string $id): Response
    {
        $text = $this->queryBus->execute(new GetInquiryFullTextQuery($id));
        if (!$text instanceof InquiryFullText) {
            throw $this->createNotFoundException('Запрос не найден.');
        }

        return $this->render('cabinet/inquiry/_text_modal.html.twig', ['text' => $text]);
    }
}
