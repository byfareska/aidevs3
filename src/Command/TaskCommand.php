<?php declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

#[AsCommand(name: 'task', description: 'Task menu')]
final class TaskCommand extends Command
{
    /**
     * @param iterable<TaskSolution> $solutions
     */
    public function __construct(
        #[AutowireIterator('app.task.solution')]
        private iterable $solutions,

        ?string $name = null)
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $question = new ChoiceQuestion('Please select the task:', [...$this->solutions], 0);
        $question->setErrorMessage('Task %s is invalid.');
        /** @var TaskSolution $command */
        $command = $this->getQuestionHelper()->ask($input, $output, $question);

        return $command->execute($input, $output);
    }

    private function getQuestionHelper(): QuestionHelper
    {
        return $this->getHelper('question');
    }
}
