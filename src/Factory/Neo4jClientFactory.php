<?php declare(strict_types=1);

namespace App\Factory;

use Laudis\Neo4j\Authentication\Authenticate;
use Laudis\Neo4j\ClientBuilder;
use Laudis\Neo4j\Contracts\ClientInterface;

final readonly class Neo4jClientFactory
{
    public function create(): ClientInterface
    {
        return ClientBuilder::create()
            ->withDriver('bolt', 'bolt://neo4j')
            ->withDefaultDriver('bolt')
            ->build();
    }
}