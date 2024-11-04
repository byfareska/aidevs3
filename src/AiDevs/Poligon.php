<?php declare(strict_types=1);

namespace App\AiDevs;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final readonly class Poligon
{
    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(env: 'POLIGON_VERIFY_ENDPOINT')]
        private string $endpoint,
        #[Autowire(env: 'AI_DEVS_API_KEY')]
        private string $apiKey,
    )
    {
    }

    public function send(string $taskName, mixed $answer): ResponseInterface
    {
        return $this->httpClient->request('POST', $this->endpoint, [
            'json' => [
                "task" => $taskName,
                "apikey" => $this->apiKey,
                "answer" => $answer,
            ]
        ]);
    }

    public function sendAndDecodeJsonBody(string $taskName, mixed $answer): mixed
    {
        return json_decode($this->send($taskName, $answer)->getContent(), false, 512, JSON_THROW_ON_ERROR);
    }
}