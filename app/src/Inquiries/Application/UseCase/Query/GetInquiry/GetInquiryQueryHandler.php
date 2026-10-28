<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetInquiry;

use App\ChemicalResistance\Application\UseCase\Query\SubstanceNamesByIds\SubstanceNamesByIdsQuery;
use App\Coatings\Application\DTO\CoatingSystems\CoatingSystemsFilterInput;
use App\Coatings\Application\DTO\CoatingSystems\FilterFacetLabel;
use App\Coatings\Application\UseCase\Query\DescribeCoatingSystemsFilter\DescribeCoatingSystemsFilterQuery;
use App\Inquiries\Application\DTO\View\InquiryAttachmentView;
use App\Inquiries\Application\DTO\View\InquiryView;
use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\File\FileStorage;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Карточка запроса для экрана. Доступ — только админ (capability). Имена вложений — из Shared/File;
 * параметры — человекочитаемыми подписями: Coatings описывает свои фасеты, вещества резолвятся в имена
 * через ChemicalResistance (всё через query-шину, домены чужие не трогаем). Текст вложений тут НЕ тянем.
 */
final readonly class GetInquiryQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryRepositoryInterface $repository,
        private FileStorage $storage,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(GetInquiryQuery $query): ?InquiryView
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $inquiry = $this->repository->findOneById($query->inquiryId);
        if (null === $inquiry) {
            return null;
        }

        $attachments = [];
        foreach ($inquiry->getAttachmentFileIds()->getList() as $fileId) {
            $stored = $this->storage->get($fileId);
            $attachments[] = new InquiryAttachmentView($fileId, null !== $stored ? $stored->originalName() : $fileId);
        }

        $params = $inquiry->getRecognizedParameters();
        $answer = $inquiry->getAnswer();

        return new InquiryView(
            id: $inquiry->getId(),
            typeLabel: $inquiry->getType()->label(),
            statusValue: $inquiry->getStatus()->value,
            statusLabel: $inquiry->getStatus()->label(),
            rawText: $inquiry->getRawText(),
            attachments: $attachments,
            paramRows: null !== $params ? $this->describe($params->toArray()) : [],
            answerTotal: $answer?->total,
            systems: null !== $answer ? $answer->systems() : [],
            warnings: $inquiry->getWarnings(),
            failureReason: $inquiry->getFailureReason(),
            sourceStrategyLabel: $inquiry->getParameterSource()?->strategy->label(),
            createdAt: $inquiry->getCreatedAt(),
        );
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<FilterFacetLabel>
     */
    private function describe(array $params): array
    {
        $input = CoatingSystemsFilterInput::fromArray($params);

        $rows = [];
        if ([] !== $input->substanceIds) {
            /** @var array<string, string> $names */
            $names = $this->queryBus->execute(new SubstanceNamesByIdsQuery($input->substanceIds));
            $labels = array_map(static fn (string $id): string => $names[$id] ?? $id, $input->substanceIds);
            $rows[] = new FilterFacetLabel('Вещества', implode(', ', $labels));
        }

        /** @var list<FilterFacetLabel> $facets */
        $facets = $this->queryBus->execute(new DescribeCoatingSystemsFilterQuery($input));

        return [...$rows, ...$facets];
    }
}
