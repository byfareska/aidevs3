<?php declare(strict_types=1);

namespace App\Factory;

use App\Modelflow\ElasticsearchEmbeddingsStore;
use App\Modelflow\CrudEmbeddingsStoreInterface;
use Elastic\Elasticsearch\ClientInterface;

final readonly class EmbeddingsStoreFactory
{
    public function __construct(
        private ClientInterface $elastic
    )
    {
    }

    public function create(string $resource): CrudEmbeddingsStoreInterface
    {
        return new ElasticsearchEmbeddingsStore($this->elastic, $resource);
    }
}