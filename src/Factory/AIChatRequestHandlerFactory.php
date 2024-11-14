<?php declare(strict_types=1);

namespace App\Factory;

use ModelflowAi\Chat\AIChatRequestHandler;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use ModelflowAi\DecisionTree\DecisionRule;
use ModelflowAi\DecisionTree\DecisionTree;
use ModelflowAi\Ollama\Ollama;
use ModelflowAi\OllamaAdapter\Chat\OllamaChatAdapter;
use ModelflowAi\OpenaiAdapter\Chat\OpenaiChatAdapterFactory;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class AIChatRequestHandlerFactory
{

    public function __construct(
        private OpenaiChatAdapterFactory $openaiChatAdapterFactory,
        private HttpClientInterface $httpClient,

        #[Autowire(env: 'OLLAMA_ENDPOINT')]
        private string $ollamaEndpoint,
    )
    {
    }

    public function create(): AIChatRequestHandlerInterface
    {
        $openAiGpt4oMini = $this->openaiChatAdapterFactory->createChatAdapter(['model' => 'gpt4o-mini']);
        $openAiGpt4o = $this->openaiChatAdapterFactory->createChatAdapter(['model' => 'gpt4o']);
        $llama3 = new OllamaChatAdapter(Ollama::factory()->withHttpClient($this->httpClient)->withBaseUrl($this->ollamaEndpoint)->make(), 'llama3.2');

        $decisionTree = new DecisionTree([
            new DecisionRule($llama3, [PrivacyCriteria::HIGH, CapabilityCriteria::BASIC]),
            new DecisionRule($openAiGpt4oMini, [PrivacyCriteria::MEDIUM, CapabilityCriteria::INTERMEDIATE]),
            new DecisionRule($openAiGpt4o, [PrivacyCriteria::MEDIUM, CapabilityCriteria::ADVANCED]),
        ]);

        return new AIChatRequestHandler($decisionTree);
    }
}