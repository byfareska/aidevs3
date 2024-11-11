<?php declare(strict_types=1);

namespace App\Command;

use Stringable;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.task.solution')]
abstract class TaskSolution extends Command implements Stringable
{
    public function __toString(): string
    {
        return "{$this->getDescription()} ({$this->getName()})";
    }
}