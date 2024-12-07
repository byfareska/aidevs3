<?php declare(strict_types=1);

namespace App\Factory;

use App\Modelflow\FeatureCriteria;
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
        $gemma = new OllamaChatAdapter(Ollama::factory()->withHttpClient($this->httpClient)->withBaseUrl($this->ollamaEndpoint)->make(), 'gemma:2b');
        $llava = new OllamaChatAdapter(Ollama::factory()->withHttpClient($this->httpClient)->withBaseUrl($this->ollamaEndpoint)->make(), 'llava');

        $decisionTree = new DecisionTree([
            new DecisionRule($gemma, [PrivacyCriteria::HIGH, CapabilityCriteria::BASIC, FeatureCriteria::TEXT_GENERATION]),
            new DecisionRule($llava, [PrivacyCriteria::HIGH, CapabilityCriteria::BASIC, FeatureCriteria::IMAGE_VISION]),
            new DecisionRule($openAiGpt4oMini, [PrivacyCriteria::MEDIUM, CapabilityCriteria::INTERMEDIATE, FeatureCriteria::TEXT_GENERATION]),
            new DecisionRule($openAiGpt4o, [PrivacyCriteria::MEDIUM, CapabilityCriteria::ADVANCED, FeatureCriteria::TEXT_GENERATION, FeatureCriteria::IMAGE_VISION]),
        ]);

        return new AIChatRequestHandler($decisionTree);
    }
}