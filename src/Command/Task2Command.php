<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\Xyz\Xyz;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:2', description: 'Task 2: 2024-11-05')]
class Task2Command extends Command
{
    public function __construct(
        private readonly Xyz $xyz,
        private readonly AIChatRequestHandlerInterface $chatRequestHandler,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $response = $this->xyz->send("READY", 0);
        $answer = $this->chatRequestHandler
            ->createRequest(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, "Please answer the question, provide the shortest answer possible, answer always and only in english"),
                new AIChatMessage(AIChatMessageRoleEnum::USER, $response->text),
            )
            ->addCriteria(PrivacyCriteria::LOW)
            ->addCriteria(CapabilityCriteria::BASIC)
            ->build()
            ->execute()
            ->getMessage()
            ->content;

        $response = $this->xyz->send($answer, $response->msgId);

        $output->writeln($response->text);

        return Command::SUCCESS;
    }
}
