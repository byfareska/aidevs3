<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\ResultSender;
use App\Modelflow\FeatureCriteria;
use App\Modelflow\FineTuningCriteria;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use PhpZip\ZipFile;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'task:17', description: 'Task 17: 2024-11-26')]
final class Task17Command extends TaskSolution
{
    private const string SYSTEM_PROMPT = 'Test data';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ResultSender $resultSender,
        private readonly AIChatRequestHandlerInterface $requestHandler,

        #[Autowire(env: 'CENTRALA_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,

        #[Autowire(env: 'TASK17_SOURCE_FILE_URL')]
        private readonly string $sourceFileUrl,

        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $kernelDir,

        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $actions = [
            'Prepare the data for fine-tuning' => fn() => $this->executePrepareData($input, $output),
            'Ask fine-tuned model for answers' => fn() => $this->executeAsk($input, $output),
            'Exit' => fn() => Command::SUCCESS,
        ];

        $question = new ChoiceQuestion('What do you want to do?', array_keys($actions));
        $action = $this->getQuestionHelper()->ask($input, $output, $question);

        return $actions[$action]();
    }

    private function executePrepareData(InputInterface $input, OutputInterface $output): int
    {
        $dir = $this->downloadAndUnzip();
        $resultFile = "{$this->getTaskDir()}/training_data.jsonl";

        if (!file_exists($resultFile)) {
            $system = ['role' => AIChatMessageRoleEnum::SYSTEM, 'content' => self::SYSTEM_PROMPT];
            $correct = ['role' => AIChatMessageRoleEnum::ASSISTANT, 'content' => '1'];
            $incorrect = ['role' => AIChatMessageRoleEnum::ASSISTANT, 'content' => '0'];

            $handleWrite = fopen($resultFile, 'wb+');

            foreach ($this->readLines("{$dir}/correct.txt") as $line) {
                $newLine = json_encode(['messages' => [$system, ['role' => AIChatMessageRoleEnum::USER, 'content' => trim($line)], $correct]], JSON_THROW_ON_ERROR);
                fwrite($handleWrite, $newLine . PHP_EOL);
            }

            foreach ($this->readLines("{$dir}/incorrect.txt") as $line) {
                $newLine = json_encode(['messages' => [$system, ['role' => AIChatMessageRoleEnum::USER, 'content' => trim($line)], $incorrect]], JSON_THROW_ON_ERROR);
                fwrite($handleWrite, $newLine . PHP_EOL);
            }

            fclose($handleWrite);
        }

        $output->writeln('File with training data for fine-tuning has been prepared. You can find it here:');
        $output->writeln($resultFile);

        return Command::SUCCESS;
    }

    private function executeAsk(InputInterface $input, OutputInterface $output): int
    {
        $dir = $this->downloadAndUnzip();

        $correct = [];

        foreach ($this->readLines("{$dir}/verify.txt") as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }

            [$id, $question] = explode('=', trim($line), 2);

            $output->write("{$question}? ");

            $response = $this->requestHandler
                ->createRequest(
                    new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::SYSTEM_PROMPT),
                    new AIChatMessage(AIChatMessageRoleEnum::USER, $question)
                )
                ->addCriteria(FineTuningCriteria::TASK17)
                ->addCriteria(PrivacyCriteria::LOW)
                ->addCriteria(CapabilityCriteria::INTERMEDIATE)
                ->addCriteria(FeatureCriteria::TEXT_GENERATION)
                ->addOptions(['temperature' => 0.0])
                ->build()
                ->execute()
                ->getMessage()
                ->content;

            $output->writeln($response);

            if (trim($response) === '1') {
                $correct[] = $id;
            }
        }

        $result = $this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'research', $correct);

        dump($result);

        return Command::SUCCESS;
    }

    private function readLines(string $filePath): iterable
    {
        $handle = fopen($filePath, 'rb+');

        while ($line = fgets($handle)) {
            yield $line;
        }

        fclose($handle);
    }

    private function downloadAndUnzip(): string
    {
        $zipDir = "{$this->getTaskDir()}/zip";

        if (file_exists($zipDir)) {
            return $zipDir;
        }

        if (!file_exists($zipDir) && !mkdir($zipDir, recursive: true) && !is_dir($zipDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $zipDir));
        }

        new ZipFile()
            ->openFromStream($this->httpClient->request('GET', $this->sourceFileUrl)->toStream())
            ->extractTo($zipDir);

        return $zipDir;
    }

    private function getTaskDir(): string
    {
        return "{$this->kernelDir}/var/task17";
    }

    private function getQuestionHelper(): QuestionHelper
    {
        return $this->getHelper('question');
    }
}