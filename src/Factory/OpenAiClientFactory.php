<?php declare(strict_types=1);

namespace App\Factory;

use OpenAI;
use OpenAI\Contracts\ClientContract;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class OpenAiClientFactory
{
    public function __construct(
        #[Autowire(env: 'OPENAI_API_KEY')]
        private string $apiKey,
        private ClientInterface $httpClient,
    )
    {
    }

    public function create(): ClientContract
    {
        return OpenAI::factory()
            ->withApiKey($this->apiKey)
            ->withOrganization(null)
            ->withHttpHeader('OpenAI-Beta', 'assistants=v1')
            ->withHttpClient($this->httpClient)
            ->make();
    }
}