<?php declare(strict_types=1);

namespace App\Command;

use App\Utility\ResultSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'task:0', description: 'Task 0')]
class Task0Command extends Command
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ResultSender $resultSender,
        #[Autowire(env: 'TASK0_ENDPOINT')]
        private string $endpoint,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $response = $this->httpClient->request('GET', $this->endpoint);
        $content = array_filter(preg_split('/\r\n|\r|\n/', $response->getContent()));
        dump($this->resultSender->sendAndDecodeJsonBody('POLIGON', $content));
        
        return Command::SUCCESS;
    }
}
