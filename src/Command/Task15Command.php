<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\DatabaseClient;
use App\AiDevs\ResultSender;
use Laudis\Neo4j\Contracts\ClientInterface;
use Laudis\Neo4j\Types\CypherList;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'task:15', description: 'Task 15: 2024-11-22')]
final class Task15Command extends TaskSolution
{
    public function __construct(
        #[Autowire(env: 'CENTRALA_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,

        private readonly ResultSender $resultSender,
        private readonly DatabaseClient $db,
        private readonly ClientInterface $neo4j,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $users = $this->db->query('SELECT * FROM users')->reply;
        $connections = $this->db->query('SELECT * FROM connections')->reply;

        $output->writeln('Creating nodes and relationships in graph database');
        $this->neo4j->run('MATCH (n) DETACH DELETE n');

        $bar = new ProgressBar($output, count($users) + count($connections));
        $bar->start();
        foreach ($users as $user) {
            $this->neo4j->run('CREATE (u:User {id: $id, username: $username, is_active: $is_active, access_level: $access_level})', (array)$user);
            $bar->advance();
        }

        foreach ($connections as $connection) {
            $this->neo4j->run('MATCH (u1:User {id: $user1_id}), (u2:User {id: $user2_id}) CREATE (u1)-[:KNOWS]->(u2)', (array)$connection);
            $bar->advance();
        }

        $bar->finish();


        $output->writeln("\nThe shortest way is:");

        /** @var CypherList $pathNodes */
        $pathNodes = $this->neo4j->run('MATCH p = shortestPath((u1:User)-[*]-(u2:User)) WHERE u1.username = $u1 AND u2.username = $u2 RETURN p', [
            'u1' => 'Rafał',
            'u2' => 'Barbara',
        ])->first()->get('p')->getNodes();

        $userNames = [];

        foreach ($pathNodes as $i => $node) {
            if ($i > 0) {
                $output->write(' → ');
            }

            $userNames[] = $userName = (string)$node->getProperty('username');
            $output->write($userName);
        }

        $output->writeln('');

        $result = $this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'connections', implode(', ', $userNames));

        dump($result);

        return Command::SUCCESS;
    }
}