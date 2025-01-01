<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\ResultSender;
use App\Factory\EmbeddingsStoreFactory;
use App\Modelflow\CrudEmbeddingsStoreInterface;
use App\Modelflow\Task12Embedding;
use DateTimeImmutable;
use ModelflowAi\Embeddings\Adapter\EmbeddingAdapterInterface;
use ModelflowAi\Embeddings\Generator\EmbeddingGeneratorInterface;
use PhpZip\ZipFile;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'task:12', description: 'Task 12: 2024-11-19')]
final class Task12Command extends TaskSolution
{
    private const string OPTION_NO_CACHE = 'no-cache';

    public function __construct(
        #[Autowire(env: 'TASK9_ENDPOINT')]
        private readonly string $sourceFileUrl,

        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,

        #[Autowire(env: 'CENTRALA_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,

        #[Autowire(env: 'TASK12_ZIP_PASSWORD')]
        private readonly string $zipPassword,

        private readonly ResultSender $resultSender,
        private readonly HttpClientInterface $httpClient,
        private readonly EmbeddingGeneratorInterface $embeddingGenerator,
        private readonly EmbeddingsStoreFactory $embeddingsStoreFactory,
        private readonly EmbeddingAdapterInterface $embeddingAdapter,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->addOption(self::OPTION_NO_CACHE, 'c', InputOption::VALUE_NONE, 'Do not use cache');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskDir = "{$this->projectDir}/var/task12";
        $store = $this->embeddingsStoreFactory->create('task12');

        if ($input->getOption(self::OPTION_NO_CACHE)) {
            $output->writeln('Removing cache...');

            if ($store->exists()) {
                $store->remove();
            }

            if (file_exists($taskDir)) {
                system("rm -rf " . escapeshellarg($taskDir));
            }
        }

        $zipDir = $this->downloadAndUnzip($taskDir);
        $fileNameToContent = $this->listFiles($zipDir);
        $embeddingsStore = $this->createEmbeddingsStore($output, $store, $fileNameToContent);

        $question = 'W raporcie, z którego dnia znajduje się wzmianka o kradzieży prototypu broni?';
        $output->writeln("Question: {$question}");
        $vector = $this->embeddingAdapter->embedText($question);

        /** @var Task12Embedding $answer */
        $answer = $embeddingsStore->similaritySearch($vector, 1)[0];
        $output->writeln("Answer file is {$answer->getNoteDate()->format('Y-m-d')}. \n\n {$answer->getContent()}");

        $result = $this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'wektory', $answer->getNoteDate()->format('Y-m-d'));

        dump($result);

        return Command::SUCCESS;
    }

    private function downloadAndUnzip(string $taskDir): string
    {
        $zipDir = "{$taskDir}/zip";

        if (file_exists($zipDir)) {
            return $zipDir;
        }

        if (!file_exists($zipDir) && !mkdir($zipDir, recursive: true) && !is_dir($zipDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $zipDir));
        }

        new ZipFile()
            ->openFromStream($this->httpClient->request('GET', $this->sourceFileUrl)->toStream())
            ->extractTo($taskDir, 'weapons_tests.zip');

        new ZipFile()
            ->openFile("{$taskDir}/weapons_tests.zip")
            ->setReadPassword($this->zipPassword)
            ->extractTo($zipDir);

        return $zipDir;
    }

    private function createEmbeddingsStore(OutputInterface $output, CrudEmbeddingsStoreInterface $store, array $files): CrudEmbeddingsStoreInterface
    {
        if ($store->exists()) {
            $output->writeln('Store already exists, using it...');

            return $store;
        }

        $output->writeln('Generating embeddings...');
        $store->create();
        $embeddings = $this->embeddingGenerator->generateEmbeddings(array_map(
            static fn(string $fileContent, string $fileName) => new Task12Embedding($fileContent, new DateTimeImmutable(str_replace('_', '-', substr($fileName, 0, -4)))),
            $files,
            array_keys($files)
        ));
        $store->addDocuments($embeddings);
        sleep(10);
        return $store;
    }

    private function listFiles(string $zipDir): array
    {
        $files = [];

        foreach (glob("{$zipDir}/do-not-share/*.txt") as $file) {
            $files[basename($file)] = file_get_contents($file);
        }

        return $files;
    }
}