<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\DatabaseClient;
use App\AiDevs\ResultSender;
use App\Modelflow\FeatureCriteria;
use App\Service\AiResponseParser;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use ModelflowAi\PromptTemplate\ChatPromptTemplate;
use stdClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'task:13', description: 'Task 13: 2024-11-20')]
final class Task13Command extends TaskSolution
{
    private const string SYSTEM_PROMPT = <<<PROMPT
You are an SQL expert. You have a database with structure like described in <structure> tag.
Write a query that will write an SQL query that will return answer question given in <question> tag.
PROMPT;

    private const string USER_PROMPT = <<<PROMPT
<structure>{structure}</structure>
<question>{question}</question>
PROMPT;

    private const string QUESTION = 'które aktywne datacenter (DC_ID) są zarządzane przez pracowników, którzy są na urlopie (is_active=0)? (wypisz tylko DC_ID)';

    public function __construct(
        #[Autowire(env: 'CENTRALA_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,

        private readonly ResultSender $resultSender,
        private readonly DatabaseClient $db,
        private readonly AIChatRequestHandlerInterface $requestHandler,
        private readonly AiResponseParser $responseParser,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln(self::QUESTION);
        $tableNameToStructure = $this->db->describeTables();

        $response = $this->requestHandler
            ->createRequest(...ChatPromptTemplate::create(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::SYSTEM_PROMPT),
                new AIChatMessage(AIChatMessageRoleEnum::USER, self::USER_PROMPT),
            )->format([
                'structure' => implode("\n\n", $tableNameToStructure),
                'question' => self::QUESTION,
            ]))
            ->addCriteria(PrivacyCriteria::LOW)
            ->addCriteria(CapabilityCriteria::ADVANCED)
            ->addCriteria(FeatureCriteria::TEXT_GENERATION)
            ->addOptions(['temperature' => 0.0])
            ->build()
            ->execute()
            ->getMessage()
            ->content;

        $response = $this->responseParser->fetchOneByCodeBlock($response, 'sql') ?? $response;
        $output->writeln($response);

        $queryResult = array_map(static fn(stdClass $r) => current((array)$r), $this->db->query($response)->reply ?? []);
        $output->writeln('Result: ' . json_encode($queryResult, JSON_THROW_ON_ERROR));
        $result = $this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'database', $queryResult);

        dump($result);

        return Command::SUCCESS;
    }

}