<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\ResultSender;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\ExpressionLanguage;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'task:3', description: 'Task 3: 2024-11-06')]
final class Task3Command extends TaskSolution
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ExpressionLanguage $expressionLanguage,
        private readonly ResultSender $resultSender,
        private readonly AIChatRequestHandlerInterface $chatRequestHandler,
        #[Autowire(env: 'CENTRALA_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,
        #[Autowire(env: 'TASK3_ENDPOINT')]
        private readonly string $endpoint,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $endpointData = str_replace('%api-key%', $this->resultSender->getApiKey(), $this->endpoint);
        $json = json_decode($this->httpClient->request('GET', $endpointData)->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $keyToQuestion = [];

        $output->writeln('Computing the math...');
        foreach ($json->{'test-data'} as $k => &$row) {
            $row->answer = (int)$this->expressionLanguage->evaluate($row->question);

            if (isset($row->test)) {
                $keyToQuestion[$k] = $row->test->q;
            }
        }

        $output->writeln('Asking the questions:');
        $answers = $this->chatRequestHandler
            ->createRequest(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, "Answer the question, for every array entry, provide the shortest answer possible, answer always and only in english in json array format without any formatting"),
                new AIChatMessage(AIChatMessageRoleEnum::USER, json_encode(array_values($keyToQuestion), JSON_THROW_ON_ERROR)),
            )
            ->addCriteria(PrivacyCriteria::LOW)
            ->addCriteria(CapabilityCriteria::BASIC)
            ->build()
            ->execute()
            ->getMessage()
            ->content;
        $answers = json_decode($answers, false, 512, JSON_THROW_ON_ERROR);

        foreach (array_values($keyToQuestion) as $key => $question) {
            $output->writeln(" - {$question}");
            $output->writeln("  - {$answers[$key]}");
        }

        foreach (array_keys($keyToQuestion) as $i => $key) {
            $json->{'test-data'}[$key]->test->a = $answers[$i];
        }
        $json->apikey = $this->resultSender->getApiKey();

        $output->writeln('Sending to Poligon...');
        dump($this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'JSON', $json));

        return Command::SUCCESS;
    }
}
