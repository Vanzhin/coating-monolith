<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Controller;

use App\Inquiries\Application\DTO\View\PagedInquiriesView;
use App\Inquiries\Application\UseCase\Query\GetPagedInquiries\GetPagedInquiriesQuery;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Shared\Application\Query\QueryBusInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: '/cabinet/inquiry', name: 'app_cabinet_inquiry_list', methods: ['GET'])]
final class ListInquiriesAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $status = $request->query->get('status');
        $status = is_string($status) && '' !== $status ? $status : null;

        $result = $this->queryBus->execute(new GetPagedInquiriesQuery(
            $status,
            max(1, $request->query->getInt('page', 1)),
            20,
        ));
        \assert($result instanceof PagedInquiriesView);

        return $this->render('cabinet/inquiry/list.html.twig', [
            'result' => $result,
            'currentStatus' => $status ?? '',
            'statuses' => InquiryStatus::cases(),
        ]);
    }
}
