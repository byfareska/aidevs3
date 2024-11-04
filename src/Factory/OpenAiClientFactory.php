<?php declare(strict_types=1);

namespace App\Factory;

use OpenAI;
use OpenAI\Contracts\ClientContract;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class OpenAiClientFactory
{
    public function __construct(
        #[Autowire(env: 'OPENAI_API_KEY')]
        private string $apiKey,
    )
    {
    }

    public function create(): ClientContract
    {
        return OpenAI::client($this->apiKey);
    }
}