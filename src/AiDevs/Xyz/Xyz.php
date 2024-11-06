<?php declare(strict_types=1);

namespace App\AiDevs\Xyz;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class Xyz
{
    public function __construct(
        private HttpClientInterface $httpClient,

        #[Autowire(env: 'TASK1_VERIFY_ENDPOINT')]
        private string $endpoint,
    )
    {
    }

    public function send(string $text, int $msgId): XyzResponse
    {
        $body = json_decode($this->httpClient->request('POST', $this->endpoint, [
            'json' => [
                "text" => $text,
                "msgID" => $msgId,
            ]
        ])->getContent(false), false, 512, JSON_THROW_ON_ERROR);

        return new XyzResponse((int)$body->msgID, (string)$body->text);
    }
}