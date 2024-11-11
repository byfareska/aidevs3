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

final readonly class AIChatRequestHandlerFactory
{
    public function __construct(
        private OpenaiChatAdapterFactory $openaiChatAdapterFactory,
    )
    {
    }

    public function create(): AIChatRequestHandlerInterface
    {
        $openAiGpt4oMini = $this->openaiChatAdapterFactory->createChatAdapter(['model' => 'gpt4o-mini']);
        $llama3 = new OllamaChatAdapter(Ollama::client(), 'llama3.2');

        $decisionTree = new DecisionTree([
            new DecisionRule($llama3, [PrivacyCriteria::HIGH, CapabilityCriteria::BASIC]),
            new DecisionRule($openAiGpt4oMini, [PrivacyCriteria::MEDIUM, CapabilityCriteria::INTERMEDIATE])
        ]);

        return new AIChatRequestHandler($decisionTree);
    }
}