<?php declare(strict_types=1);

namespace App\Command;

use App\Modelflow\FeatureCriteria;
use Facebook\WebDriver\Remote\RemoteWebElement;
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
use Symfony\Component\Panther\Client;

#[AsCommand(name: 'task:1', description: 'Task 1: 2024-11-04')]
final class Task1Command extends TaskSolution
{
    private const string QUESTION_SELECTOR = '#human-question';

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,

        #[Autowire(env: 'TASK1_ENDPOINT')]
        private readonly string $endpoint,

        #[Autowire(env: 'TASK1_USERNAME')]
        private readonly string $username,

        #[Autowire(env: 'TASK1_PASSWORD')]
        private readonly string $password,

        private readonly AIChatRequestHandlerInterface $chatRequestHandler,
        private readonly Client $browser,
        ?string $name = null,
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $webpage = $this->browser->request('GET', $this->endpoint);
        $question = $this->browser->waitFor(self::QUESTION_SELECTOR)->filter(self::QUESTION_SELECTOR)->text();
        $answer = $this->chatRequestHandler
            ->createRequest(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, "Proszę o odpowiedź na pytanie, udziel jak najkrótszej odpowiedzi, najlepiej w jednym słowie"),
                new AIChatMessage(AIChatMessageRoleEnum::USER, $question),
            )
            ->addCriteria(PrivacyCriteria::LOW)
            ->addCriteria(CapabilityCriteria::BASIC)
            ->addCriteria(FeatureCriteria::TEXT_GENERATION)
            ->build()
            ->execute()
            ->getMessage()
            ->content;

        $form = $webpage->filter('form')->form();
        $form->get('username')->setValue($this->username);
        $form->get('password')->setValue($this->password);
        $form->get('answer')->setValue($answer);
        $afterSubmit = $this->browser->submit($form);

        $path = sprintf('%s/var/screenshots/task1-%s.png', $this->projectDir, time());
        $this->browser->takeScreenshot($path);
        $output->writeln("Screenshot saved to: {$path}");
        $output->writeln("Current URL is {$this->browser->getCurrentURL()}");
        $output->writeln("Found links:");
        foreach ($afterSubmit->filter('a')->getIterator() as $anchor) {
            /** @var $anchor RemoteWebElement */
            $output->writeln("  - {$anchor->getText()}: {$anchor->getAttribute('href')}");
        }

        return Command::SUCCESS;
    }
}
