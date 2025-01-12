<?php declare(strict_types=1);

namespace App\Service;

use App\Modelflow\FeatureCriteria;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use ModelflowAi\PromptTemplate\ChatPromptTemplate;
use Psr\Log\LoggerInterface;

final readonly class Grudziadz
{
    private const string SYSTEM_PROMPT = <<<PROMPT
This matrix shows the map. Follow received instructions and tell where you are. Answer in polish.
[
    ['START', 'ŁĄKA', 'DRZEWO', 'DOM'],
    ['ŁĄKA', 'MŁYN', 'ŁĄKA', 'ŁĄKA'],
    ['ŁĄKA', 'ŁĄKA', 'SKAŁY', 'DRZEWA'],
    ['SKAŁY', 'SKAŁY', 'SAMOCHÓD', 'JASKINIA']
]
Reply in format:
<thinking>here your thinking</thinking>
<result>here place name from matrix</result>
PROMPT;

    private const string USER_PROMPT = <<<RESULT
<instructions>{instructions}</instructions>
RESULT;

    public function __construct(
        private AIChatRequestHandlerInterface $requestHandler,
        private AiResponseParser $responseParser,
        private LoggerInterface $logger,
    )
    {
    }

    public function trip(string $moves): string
    {
        $content = $this->requestHandler
            ->createRequest(
                ...ChatPromptTemplate::create(
                    new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::SYSTEM_PROMPT),
                    new AIChatMessage(AIChatMessageRoleEnum::USER, self::USER_PROMPT)
                )->format([
                    'instructions' => $moves,
                ])
            )
            ->addCriteria(PrivacyCriteria::LOW)
            ->addCriteria(CapabilityCriteria::ADVANCED)
            ->addCriteria(FeatureCriteria::TEXT_GENERATION)
            ->addOptions(['temperature' => 0.0])
            ->build()
            ->execute()
            ->getMessage()
            ->content;

        $this->logger->info('Response from AI', ['content' => $content]);

        return $this->responseParser->fetchOneByTag(
            $content,
            'result'
        );
    }
}