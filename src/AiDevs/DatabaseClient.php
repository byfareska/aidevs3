<?php declare(strict_types=1);

namespace App\AiDevs;

use stdClass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class DatabaseClient
{
    public function __construct(
        #[Autowire(env: 'TASK13_ENDPOINT')]
        private string $databaseEndpoint,
        private ResultSender $resultSender,
        private HttpClientInterface $httpClient,
    )
    {
    }

    public function query(string $query): stdClass
    {
        $content = $this->httpClient->request('POST', $this->databaseEndpoint, [
            'json' => [
                'query' => $query,
                'task' => 'database',
                'apikey' => $this->resultSender->getApiKey(),
            ],
        ])->getContent();

        return json_decode($content, false, 512, JSON_THROW_ON_ERROR);
    }

    public function describeTables(): array
    {
        $availableTableNames = array_map(static fn(stdClass $row) => $row->Tables_in_banan, $this->query('show tables')->reply);

        $result = [];

        foreach ($availableTableNames as $tableName) {
            $result[$tableName] = $this->query("show create table {$tableName}")->reply[0]->{'Create Table'};
        }

        return $result;
    }
}