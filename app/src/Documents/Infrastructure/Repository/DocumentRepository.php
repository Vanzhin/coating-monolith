<?php

declare(strict_types=1);

namespace App\Documents\Infrastructure\Repository;

use App\Documents\Domain\Aggregate\Document\Document;
use App\Documents\Domain\Repository\DocumentRepositoryInterface;
use App\Shared\Infrastructure\Database\ES\ConfigLoader;
use App\Shared\Infrastructure\Database\ES\QueryBuilder;
use Elastic\Elasticsearch\ClientInterface;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;

class DocumentRepository implements DocumentRepositoryInterface
{
    private string $default = 'documents';

    public function __construct(
        private ClientInterface $client,
        private QueryBuilder $queryBuilder,
        private ConfigLoader $defaultConfig,
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
}