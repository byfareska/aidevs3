<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\ResultSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'task:8', description: 'Task 7: 2024-11-13')]
final class Task8Command extends TaskSolution
{
    private const string ACTION_SHOW_DESCRIPTION = 'Show description of the robot';
    private const string ACTION_SEND_THE_IMAGE = 'Send the image';

    public function __construct(
        private readonly ResultSender $resultSender,
        private readonly HttpClientInterface $httpClient,

        #[Autowire(env: 'APP_URL')]
        private readonly string $appUrl,

        #[Autowire(env: 'TASK8_ENDPOINT')]
        private readonly string $endpoint,

        #[Autowire(env: 'CENTRALA_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,

        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $question = new ChoiceQuestion('What you want to do?', [
            self::ACTION_SHOW_DESCRIPTION,
            self::ACTION_SEND_THE_IMAGE,
        ]);
        $action = $this->getQuestionHelper()->ask($input, $output, $question);

        if ($action === self::ACTION_SEND_THE_IMAGE) {
            $response = $this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'robotid', "{$this->appUrl}/img/task8.png");
            dump($response);

            return Command::SUCCESS;
        }

        // I'm using ComfyUI. So I've manually generated the image.
        $endpointDescription = str_replace('%api-key%', $this->resultSender->getApiKey(), $this->endpoint);
        $response = $this->httpClient->request('GET', $endpointDescription);
        $description = json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR)->description;
        $output->writeln($description);

        return Command::SUCCESS;
    }

    private function getQuestionHelper(): QuestionHelper
    {
        return $this->getHelper('question');
    }
}