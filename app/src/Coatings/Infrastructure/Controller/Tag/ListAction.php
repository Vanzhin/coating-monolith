<?php

declare(strict_types=1);

namespace App\Coatings\Infrastructure\Controller\Tag;

use App\Coatings\Application\UseCase\Query\GetPagedTags\GetPagedTagsQuery;
use App\Coatings\Domain\Repository\TagsFilter;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Domain\Repository\Pager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    path: '/cabinet/coating/tag',
    name: 'app_cabinet_coating_tag_list',
    methods: ['GET'],
)]
#[IsGranted('ROLE_ADMIN')]
final class ListAction extends AbstractController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    public function __invoke(Request $request): Response
    {
        $page = (int) $request->query->get('page', 1);
        $perPage = 50;

        $filter = new TagsFilter(
            pager: new Pager($page, $perPage),
        );

        $result = $this->queryBus->execute(new GetPagedTagsQuery($filter));

        return $this->render('admin/coating/tag/list.html.twig', [
            'tags' => $result->coatingTags,
            'pager' => $result->pager,
        ]);
    }
}
