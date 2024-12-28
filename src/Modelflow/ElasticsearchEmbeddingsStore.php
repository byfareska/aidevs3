<?php declare(strict_types=1);

namespace App\Modelflow;

use stdClass;

final class ElasticsearchEmbeddingsStore extends \ModelflowAi\Embeddings\Store\Elasticsearch\ElasticsearchEmbeddingsStore implements CrudEmbeddingsStoreInterface
{
    public function exists(): bool
    {
        try {
            return $this->client->indices()->exists(['index' => $this->indexName])->asBool();
        } catch (\Throwable) {
            return false;
        }
    }

    public function isEmpty(): bool
    {
        try {
            return (
                    $this->client->search([
                        'index' => $this->indexName,
                        'size' => 0, // No need to fetch documents, just count
                        'body' => [
                            'query' => [
                                'match_all' => new stdClass() // Match all documents
                            ]
                        ]
                    ])['hits']['total']['value'] ?? 0
                ) === 0;
        } catch (\Throwable) {
            return true;
        }
    }

    public function create(): void
    {
        $this->client->indices()->create(['index' => $this->indexName]);
    }

    public function remove(): void
    {
        $this->client->indices()->delete(['index' => $this->indexName]);
    }
}