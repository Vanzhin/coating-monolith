<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Database\ES;

use App\Shared\Infrastructure\Database\ES\Enum\Operator;
use App\Shared\Infrastructure\Database\ES\Enum\Type;

class QueryBuilder
{
    private Query $query;
    private array $nestedPaths = [];

    public function __construct()
    {
        $this->query = new Query();
    }
    public function reset(): self
    {
        $this->query = new Query();
        $this->nestedPaths = [];
        return $this;
    }

    public function getQuery(): Query
    {
        $this->buildNestedQueries();
        return $this->query;
    }

    public function addMust(Type $type, string $key, mixed $value, ?array $options = null): self
    {
        return $this->add($type, $key, $value, Operator::MUST, $options);
    }

    public function addShould(Type $type, string $key, mixed $value, ?array $options = null): self
    {
        return $this->add($type, $key, $value, Operator::SHOULD, $options);
    }

    public function addFilter(Type $type, string $key, mixed $value, ?array $options = null): self
    {
        return $this->add($type, $key, $value, Operator::FILTER, $options);
    }

    public function addMustNot(Type $type, string $key, mixed $value, ?array $options = null): self
    {
        return $this->add($type, $key, $value, Operator::MUST_NOT, $options);
    }

    public function addNested(string $path, callable $callback): self
    {
        $builder = new self();
        $callback($builder);
        $this->nestedPaths[$path][] = $builder->getQuery()->getQuery();

        return $this;
    }

    public function setLimit(int $limit): self
    {
        $this->query->setSize($limit);
        return $this;
    }

    public function setOffset(int $offset): self
    {
        $this->query->setFrom($offset);
        return $this;
    }

    public function setSort(string $field, string $order = 'asc'): self
    {
        $this->query->addSort([$field => ['order' => $order]]);
        return $this;
    }

    public function addAggregation(array $aggregation): self
    {
        // Если передана одна агрегация без имени, используем ключ как имя
        if (count($aggregation) === 1 && !isset($aggregation[0])) {
            $name = key($aggregation);
            $body = current($aggregation);
            $this->query->addAggregation($name, $body);
        } else {
            // Для сложных агрегаций добавляем целиком
            foreach ($aggregation as $name => $body) {
                $this->query->addAggregation($name, $body);
            }
        }

        return $this;
    }

    private function add(Type $type, string $key, mixed $value, Operator $operator, ?array $options = null): self
    {
        if ($type === Type::MATCH && is_string($value)) {
            return $this->handleMatchQuery($key, $value, $operator, $options);
        }

        $queryPart = $this->buildQueryPart($type, $key, $value, $options);
        $this->addQueryPart($queryPart, $operator);

        return $this;
    }

    private function handleMatchQuery(string $key, string $value, Operator $operator, ?array $options): self
    {
        // Нормализуем ввод - удаляем пробелы между числами и буквами
        $normalizedValue = preg_replace('/(\d+)\s*([а-яА-Яa-zA-Z]+)/u', '$1$2', $value);

        // Разбиваем на токены для сложных запросов
        $tokens = preg_split('/\s+/', $normalizedValue);

        // Для однословных запросов (как "750К") используем match_phrase с wildcard
        if (count($tokens) === 1) {
            $this->addQueryPart([
                'bool' => [
                    'should' => [
                        [
                            'match_phrase' => [
                                $key => [
                                    'query' => $normalizedValue,
                                    'slop' => 2
                                ]
                            ]
                        ],
                        [
                            'wildcard' => [
                                $key => [
                                    'value' => "*{$normalizedValue}*",
                                    'case_insensitive' => true
                                ]
                            ]
                        ]
                    ],
                    'minimum_should_match' => 1
                ]
            ], $operator);

            return $this;
        }

        // Для многословных запросов используем комбинацию подходов
        $boolQuery = ['bool' => ['must' => []]];

        foreach ($tokens as $token) {
            if (preg_match('/\d+/', $token)) {
                // Для токенов с числами используем wildcard
                $boolQuery['bool']['must'][] = [
                    'wildcard' => [
                        $key => [
                            'value' => "*{$token}*",
                            'case_insensitive' => true
                        ]
                    ]
                ];
            } else {
                // Для текстовых токенов используем match с fuzziness
                $boolQuery['bool']['must'][] = [
                    'match' => [
                        $key => [
                            'query' => $token,
                            'fuzziness' => 'AUTO',
                            'operator' => 'AND'
                        ]
                    ]
                ];
            }
        }

        return $this->addRawQuery($boolQuery, $operator);
    }

    private function addQueryPart(array $queryPart, Operator $operator): void
    {
        match ($operator) {
            Operator::MUST_NOT => $this->query->addMustNot($queryPart),
            Operator::FILTER => $this->query->addFilter($queryPart),
            Operator::SHOULD => $this->query->addShould($queryPart),
            default => $this->query->addMust($queryPart),
        };
    }

    private function addRawQuery(array $query, Operator $operator): self
    {
        $this->addQueryPart($query, $operator);
        return $this;
    }

    private function extractNumbers(string $value): array
    {
        preg_match_all('/\d+/', $value, $matches);
        return $matches[0] ?? [];
    }

    private function removeNumbers(string $value): string
    {
        return preg_replace('/\d+/', '', $value);
    }

    private function buildQueryPart(Type $type, string $key, mixed $value, ?array $options): array
    {
        return match ($type) {
            Type::MATCH => $this->buildMatchQuery($key, $value, $options),
            Type::TERM => $this->buildTermQuery($key, $value, $options),
            Type::RANGE => $this->buildRangeQuery($key, $value),
            Type::EXISTS => $this->buildExistsQuery($key),
            Type::WILDCARD => $this->buildWildcardQuery($key, $value),
            Type::REGEXP => $this->buildRegexpQuery($key, $value),
            Type::BOOL => $this->buildBoolQuery($value),
            default => throw new \InvalidArgumentException("Unsupported query type: {$type->value}"),
        };
    }

    private function buildMatchQuery(string $key, mixed $value, ?array $options): array
    {
        $query = ['query' => $value];
        $query = $options ? array_merge($query, $options) : $query;

        if (!isset($query['fuzziness'])) {
            $query['fuzziness'] = 'auto';
        }

        return ['match' => [$key => $query]];
    }

    private function buildTermQuery(string $key, mixed $value, ?array $options): array
    {
        $field = str_ends_with($key, '.keyword') ? $key : $key.'.keyword';
        $query = ['value' => $value];

        return ['term' => [$field => ($options ? array_merge($query, $options) : $query)]];
    }

    private function buildRangeQuery(string $key, array $value): array
    {
        return ['range' => [$key => $value]];
    }

    private function buildExistsQuery(string $key): array
    {
        return ['exists' => ['field' => $key]];
    }

    private function buildWildcardQuery(string $key, string $value): array
    {
        return ['wildcard' => [$key => ['value' => $value]]];
    }

    private function buildRegexpQuery(string $key, string $value): array
    {
        return ['regexp' => [$key => ['value' => $value]]];
    }

    private function buildBoolQuery(array $value): array
    {
        return ['bool' => $value];
    }

    private function buildNestedQueries(): void
    {
        foreach ($this->nestedPaths as $path => $queries) {
            foreach ($queries as $query) {
                $this->query->addMust([
                    'nested' => [
                        'path' => $path,
                        'query' => $query['bool'] ?? $query,
                        'score_mode' => 'avg'
                    ]
                ]);
            }
        }
    }
}