<?php declare(strict_types=1);

namespace App\Factory;

use App\Modelflow\FeatureCriteria;
use App\Modelflow\FineTuningCriteria;
use App\Modelflow\ProviderCriteria;
use Gemini;
use Gemini\Enums\ModelType;
use ModelflowAi\Chat\AIChatRequestHandler;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use ModelflowAi\DecisionTree\DecisionRule;
use ModelflowAi\DecisionTree\DecisionTree;
use ModelflowAi\GoogleGeminiAdapter\Chat\GoogleGeminiChatAdapter;
use ModelflowAi\Ollama\Ollama;
use ModelflowAi\OllamaAdapter\Chat\OllamaChatAdapter;
use ModelflowAi\OpenaiAdapter\Chat\OpenaiChatAdapterFactory;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class AIChatRequestHandlerFactory
{

    public function __construct(
        private OpenaiChatAdapterFactory $openaiChatAdapterFactory,
        private HttpClientInterface $httpClient,
        private ClientInterface $psrHttpClient,

        #[Autowire(env: 'OLLAMA_ENDPOINT')]
        private string $ollamaEndpoint,

        #[Autowire(env: 'GEMINI_API_KEY')]
        private string $geminiApiKey,

        #[Autowire(env: 'TASK17_MODEL_ID')]
        private string $task17ModelId,
    )
    {
    }

    public function create(): AIChatRequestHandlerInterface
    {
        $googleGeminiClient = Gemini::factory()
            ->withApiKey($this->geminiApiKey)
            ->withHttpClient($this->psrHttpClient)
            ->make();

        $openAiFactory = fn(string $model) => $this->openaiChatAdapterFactory->createChatAdapter(['model' => $model]);

        $openAiGpt4o = $openAiFactory('gpt4o');
        $gemma = new OllamaChatAdapter(Ollama::factory()->withHttpClient($this->httpClient)->withBaseUrl($this->ollamaEndpoint)->make(), 'gemma:2b');
        $llava = new OllamaChatAdapter(Ollama::factory()->withHttpClient($this->httpClient)->withBaseUrl($this->ollamaEndpoint)->make(), 'llava');
        $geminiPro = new GoogleGeminiChatAdapter($googleGeminiClient, ModelType::GEMINI_PRO->value);
        $geminiProVision = new GoogleGeminiChatAdapter($googleGeminiClient, ModelType::GEMINI_FLASH->value);

        $gpt4oMiniCriteria = [PrivacyCriteria::MEDIUM, CapabilityCriteria::INTERMEDIATE, FeatureCriteria::TEXT_GENERATION, ProviderCriteria::OPENAI];

        $decisionTree = new DecisionTree([
            new DecisionRule($gemma, [FineTuningCriteria::NONE, PrivacyCriteria::HIGH, CapabilityCriteria::BASIC, FeatureCriteria::TEXT_GENERATION, ProviderCriteria::LOCAL]),
            new DecisionRule($llava, [FineTuningCriteria::NONE, PrivacyCriteria::HIGH, CapabilityCriteria::BASIC, FeatureCriteria::IMAGE_VISION, ProviderCriteria::LOCAL]),
            new DecisionRule($openAiFactory('gpt4o-mini'), [FineTuningCriteria::NONE, ...$gpt4oMiniCriteria]),
            new DecisionRule($openAiGpt4o, [FineTuningCriteria::NONE, PrivacyCriteria::MEDIUM, CapabilityCriteria::ADVANCED, FeatureCriteria::TEXT_GENERATION, ProviderCriteria::OPENAI]),
            new DecisionRule($openAiGpt4o, [FineTuningCriteria::NONE, PrivacyCriteria::MEDIUM, CapabilityCriteria::ADVANCED, FeatureCriteria::IMAGE_VISION, ProviderCriteria::OPENAI]),
            new DecisionRule($geminiPro, [FineTuningCriteria::NONE, PrivacyCriteria::MEDIUM, CapabilityCriteria::ADVANCED, FeatureCriteria::TEXT_GENERATION, ProviderCriteria::GOOGLE]),
            new DecisionRule($geminiProVision, [FineTuningCriteria::NONE, PrivacyCriteria::MEDIUM, CapabilityCriteria::ADVANCED, FeatureCriteria::IMAGE_VISION, ProviderCriteria::GOOGLE]),
            new DecisionRule($openAiFactory($this->task17ModelId), [FineTuningCriteria::TASK17, ...$gpt4oMiniCriteria]),
        ]);

        return new AIChatRequestHandler($decisionTree);
    }
}