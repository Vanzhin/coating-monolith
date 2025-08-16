<?php

declare(strict_types=1);

namespace App\Documents\Infrastructure\Repository;

use App\Documents\Domain\Aggregate\Document\Document;
use App\Documents\Domain\Repository\DocumentFilter;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Documents\Infrastructure\Mapper\DocumentMapper;
use App\Shared\Domain\Repository\PaginationResult;
use App\Shared\Infrastructure\Database\ES\ConfigLoader;
use App\Shared\Infrastructure\Database\ES\Enum\Type;
use App\Shared\Infrastructure\Database\ES\QueryBuilder;
use Elastic\Elasticsearch\ClientInterface;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ElasticsearchException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Psr\Log\LoggerInterface;

class DocumentRepository implements DocumentRepositoryInterface
{
    private string $default = 'documents';

    public function __construct(
        private ClientInterface $client,
        private QueryBuilder $queryBuilder,
        private ConfigLoader $defaultConfig,
        private DocumentMapper $documentMapper,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ClientResponseException
     * @throws ServerResponseException
     * @throws MissingParameterException
     */
    public function dbCreate(string $dbTitle, ?array $mappings = null, ?array $settings = null): bool
    {
        $data = [
            'index' => $dbTitle,
            'body' => [
                'settings' => $this->defaultConfig->loadFromConfig($this->default)['settings'],
                'mappings' => $this->defaultConfig->loadFromConfig($this->default)['mappings']
            ]
        ];
        if ($mappings) {
            $data['body']['mappings'] = $mappings;
        }
        if ($settings) {
            $data['body']['settings'] = $settings;
        }

        return $this->client->index($data)->asBool();
    }

    /**
     * @throws ServerResponseException
     * @throws ClientResponseException
     * @throws MissingParameterException
     */
    public function dbDelete(string $dbTitle): bool
    {
        $data = [
            'index' => $dbTitle,
        ];

        return $this->client->indices()->delete($data)->asBool();
    }

    public function bulkInsert(string $data, ?string $dbName = null): bool
    {
        return $this->client->bulk([
            'index' => $dbName ?? $this->default,
            'body' => $data,
        ])->asBool();
    }

    public function save(Document $document): void
    {
        dd($document);
    }

    public function search(DocumentFilter $filter): PaginationResult
    {
        $this->queryBuilder->reset();

        $this->applyFilters($filter);
        $this->applyPagination($filter);

        $result = $this->executeSearch($filter);

        return $this->createPaginationResult($result);
    }

    public function findCountByCategory(DocumentFilter $filter): array
    {
        $this->queryBuilder->reset();
        // 1. Применяем все фильтры из DocumentFilter
        $this->applyFilters($filter);

        // 2. Отключаем пагинацию (нам нужны все результаты для агрегации)
        $this->queryBuilder
            ->setLimit(0)
            ->setOffset(0);

        // 3. Добавляем агрегацию по категориям
        $this->queryBuilder->addAggregation([
            'categories' => [
                'terms' => [
                    'field' => 'category.keyword',
                    'size' => 1000, // Достаточно большое число, чтобы получить все категории
                    'min_doc_count' => 1, // Только категории с документами
                    'order' => ['_count' => 'desc'] // Сортировка по количеству
                ]
            ]
        ]);

        // 4. Выполняем запрос
        $result = $this->executeSearch($filter);

        // 5. Форматируем результат
        return $this->formatCategoryStats($result);
    }

    private function formatCategoryStats(array $result): array
    {
        $stats = [];
        if (!empty($result['aggregations']['categories']['buckets'])) {
            foreach ($result['aggregations']['categories']['buckets'] as $bucket) {
                $stats[$bucket['key']] = $bucket['doc_count'];
            }
        }

        return $stats;
    }

    private function applyFilters(DocumentFilter $filter): void
    {
        if ($searchTerm = $filter->getSearch()) {
            $this->queryBuilder->addMust(
                Type::MATCH,
                'products.title',
                $searchTerm,
                ['fuzziness' => 'AUTO']
            );
        }

        if ($title = $filter->getTitle()) {
            $this->queryBuilder->addMust(
                Type::MATCH,
                'title',
                $title,
                ['boost' => 2.0] // Повышаем релевантность title
            );
        }

        if ($category = $filter->getCategory()) {
            $this->queryBuilder->addMust(
                Type::TERM,
                'category',
                $category
            );
        }

        if ($categoryTypes = $filter->getCategoryTypes()) {
            $this->applyCategoryTypesFilter($categoryTypes);
        }
    }

    private function applyCategoryTypesFilter(array $categoryTypes): void
    {
        $types = array_map(fn($item) => $item->value, $categoryTypes);

        // Используем минимум 1 должно совпасть
        $this->queryBuilder->getQuery()->setMinimumShouldMatch(1);

        foreach ($types as $type) {
            $this->queryBuilder->addShould(
                Type::TERM,
                'category',
                $type,
                ['boost' => 1.5] // Повышаем вес категорий
            );
        }
    }

    private function applyPagination(DocumentFilter $filter): void
    {
        $this->queryBuilder
            ->setLimit($filter->getPager()->getLimit())
            ->setOffset($filter->getPager()->getOffset());
    }

    private function executeSearch(DocumentFilter $filter): array
    {
        try {
            return $this->client->search([
                'index' => $filter->getIndex() ?? $this->default,
                'body' => $this->queryBuilder->getQuery()->jsonSerialize()
            ])->asArray();
        } catch (ElasticsearchException $e) {
            $this->logger->error('Elasticsearch search failed', [
                'error' => $e->getMessage(),
                'query' => $this->queryBuilder->getQuery()->jsonSerialize()
            ]);

            return ['hits' => ['total' => ['value' => 0], 'hits' => []]];
        }
    }

    private function createPaginationResult(array $result): PaginationResult
    {
        $total = $result['hits']['total']['value'] ?? 0;
        $items = array_map(
            fn($document) => $this->documentMapper->mapEntity($document),
            $result['hits']['hits'] ?? []
        );

        return new PaginationResult($items, $total);
    }
}