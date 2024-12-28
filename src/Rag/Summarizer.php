<?php declare(strict_types=1);

namespace App\Rag;

use App\Modelflow\FeatureCriteria;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use ModelflowAi\PromptTemplate\ChatPromptTemplate;

final readonly class Summarizer
{
    private const string SYSTEM_PROMPT = <<<PROMPT
Summarize the text you will receive. The summary should be concise and capture the main points of the text.
PROMPT;

    public function __construct(
        private AIChatRequestHandlerInterface $requestHandler,
    )
    {
    }

    public function summarize(string $content): string
    {
        return $this->requestHandler
            ->createRequest(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::SYSTEM_PROMPT),
                new AIChatMessage(AIChatMessageRoleEnum::USER, $content)
            )
            ->addCriteria(PrivacyCriteria::LOW)
            ->addCriteria(CapabilityCriteria::INTERMEDIATE)
            ->addCriteria(FeatureCriteria::TEXT_GENERATION)
            ->build()
            ->execute()
            ->getMessage()
            ->content;
    }
}