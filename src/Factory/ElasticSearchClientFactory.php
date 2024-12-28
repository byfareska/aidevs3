<?php declare(strict_types=1);

namespace App\Factory;

use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ElasticSearchClientFactory
{
    public function __construct(
        #[Autowire(env: 'ELASTICSEARCH_URL')]
        private string $elasticSearchHost,
        private ClientInterface $httpClient,
    )
    {
    }

    public function create(): Client
    {
        return ClientBuilder::create()
            ->setHttpClient($this->httpClient)
            ->setHosts([$this->elasticSearchHost])
            ->build();
    }
}
