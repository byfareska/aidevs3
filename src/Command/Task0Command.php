<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\ResultSender;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'task:0', description: 'Task 0')]
final class Task0Command extends TaskSolution
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ResultSender $poligon,
        #[Autowire(env: 'TASK0_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,
        #[Autowire(env: 'TASK0_ENDPOINT')]
        private readonly string $endpointData,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $response = $this->httpClient->request('GET', $this->endpointData);
        $content = array_filter(preg_split('/\r\n|\r|\n/', $response->getContent()));
        dump($this->poligon->sendAndDecodeJsonBody($this->endpointVerify, 'POLIGON', $content));

        return Command::SUCCESS;
    }
}
