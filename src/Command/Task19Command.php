<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\ResultSender;
use App\Controller\Task19Controller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\RouterInterface;

#[AsCommand(name: 'task:19', description: 'Task 19: 2024-11-28')]
final class Task19Command extends TaskSolution
{
    public function __construct(
        private readonly ResultSender $resultSender,
        private readonly RouterInterface $router,

        #[Autowire(env: 'CENTRALA_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,

        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $this->getQuestionHelper()->ask($input, $output, new Question('What is the host of your application? ', 'http://szarafinowski.pl:8080'));
        $webhookUrl = $host . $this->router->generate(Task19Controller::NAME);
        $output->writeln("Waiting for instructions on {$webhookUrl}");
        $result = $this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'webhook', $webhookUrl);
        dump($result);

        return Command::SUCCESS;
    }

    private function getQuestionHelper(): QuestionHelper
    {
        return $this->getHelper('question');
    }
}