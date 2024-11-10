<?php declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:4', description: 'Task 4: 2024-11-07')]
class Task4Command extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $prompt = <<<PROMPT
this matrix shows the maze. create path from start (S) to finish (F). You can't step on 1.

[
 [0,1,0,0,0,0],
 [0,0,0,1,0,0],
 [0,1,0,1,0,0],
 [S,1,0,0,0,F]
]

allowed moves are UP, DOWN, LEFT, RIGHT.

reply in format

<thinking>
here your thinking
</thinking>

<RESULT>
{
 "steps": "UP, RIGHT, DOWN, LEFT"
}
</RESULT>
PROMPT;

        $output->writeln($prompt);

        return Command::SUCCESS;
    }
}
