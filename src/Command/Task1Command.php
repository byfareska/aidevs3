<?php declare(strict_types=1);

namespace App\Command;

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
class Task1Command extends Command
{
    private const string QUESTION_SELECTOR = '#human-question';

    public function __construct(
        private AIChatRequestHandlerInterface $chatRequestHandler,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
        #[Autowire(env: 'TASK1_ENDPOINT')]
        private string $endpoint,
        #[Autowire(env: 'TASK1_USERNAME')]
        private string $username,
        #[Autowire(env: 'TASK1_PASSWORD')]
        private string $password,
        ?string $name = null,
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = Client::createChromeClient();

        $webpage = $client->request('GET', $this->endpoint);

        $question = $client->waitFor(self::QUESTION_SELECTOR)->filter(self::QUESTION_SELECTOR)->text();
        $answer = $this->chatRequestHandler
            ->createRequest(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, "Proszę o odpowiedź na pytanie, udziel jak najkrótszej odpowiedzi, najlepiej w jednym słowie"),
                new AIChatMessage(AIChatMessageRoleEnum::USER, $question),
            )
            ->addCriteria(PrivacyCriteria::LOW)
            ->addCriteria(CapabilityCriteria::BASIC)
            ->build()
            ->execute()
            ->getMessage()
            ->content;

        $form = $webpage->filter('form')->form();
        $form->get('username')->setValue($this->username);
        $form->get('password')->setValue($this->password);
        $form->get('answer')->setValue($answer);
        $afterSubmit = $client->submit($form);

        $path = sprintf('%s/var/screenshots/task1-%s.png', $this->projectDir, time());
        $client->takeScreenshot($path);
        $output->writeln("Screenshot saved to: {$path}");
        $output->writeln("Current URL is {$client->getCurrentURL()}");
        $output->writeln("Found links:");
        foreach ($afterSubmit->filter('a')->getIterator() as $anchor) {
            /** @var $anchor RemoteWebElement */
            $output->writeln("  - {$anchor->getText()}: {$anchor->getAttribute('href')}");
        }

        return Command::SUCCESS;
    }
}
