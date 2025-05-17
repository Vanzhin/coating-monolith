<?php
declare(strict_types=1);


namespace App\Shared\Infrastructure\Database\ES;

class QueryBuilder
{

    private Query $query;

    public function __construct()
    {
        $this->query = new Query();

    }

    public function getQuery(): Query
    {
        return $this->query;
    }

    public function addMust(Type $type, string $key, mixed $data): void
    {
        $this->add($type, $key, $data);
    }

    public function addShould(Type $type, string $key, mixed $data): void
    {
        $this->add($type, $key, $data, QueryParamType::SHOULD);
    }

    public function addFilter(Type $type, string $key, mixed $data): void
    {
        $this->add($type, $key, $data, QueryParamType::FILTER);
    }

    public function addMustNot(Type $type, string $key, mixed $data): void
    {
        $this->add($type, $key, $data, QueryParamType::MUST_NOT);
    }

    public function addLimit(int $limit): void
    {
        $this->query->setSize($limit);
    }

    public function addPage(int $page): void
    {
        $this->query->setFrom($page);
    }

    private function add(Type $type, string $key, mixed $data, QueryParamType $paramType = QueryParamType::MUST): void
    {
        $values = [];

        if ($type === Type::MATCH) {
            $values[$type->value][$key] = $this->parseMatch($data);
        }
        if ($type === Type::TERM) {
            $values[$type->value][$this->parseTermKey($key)] = $data;
        }
        if ($type === Type::RANGE) {
            $values[$type->value][$key] = $data;
        }
        $this->addTo($paramType, $values);
    }

    private function addTo(QueryParamType $paramType, array $data): void
    {
        match ($paramType) {
            QueryParamType::MUST_NOT => $this->query->addMustNot($data),
            QueryParamType::FILTER => $this->query->addFilter($data),
            QueryParamType::SHOULD => $this->query->addShould($data),
            default => $this->query->addMust($data),
        };
    }

    private function parseMatch(string $data): array
    {
        return ['query' => $data, 'fuzziness' => 'auto'];
    }

    private function parseTermKey(string $key): string
    {
        return $key . '.keyword';
    }


}