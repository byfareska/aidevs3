<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\ResultSender;
use App\Modelflow\FeatureCriteria;
use App\Service\AiResponseParser;
use App\Service\Omniparse;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use ModelflowAi\PromptTemplate\ChatPromptTemplate;
use PhpZip\ZipFile;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'task:6', description: 'Task 6: 2024-11-11')]
final class Task6Command extends TaskSolution
{
    private const string SYSTEM_PROMPT = <<<PROMPT
Otrzymasz kilka transkrypcji z rozmów ze świadkami. Na podstawie transkrypcji powiedz mi na jakiej ulicy jest instytut uczelni na której wykłada Andrzej Maj. Opisz swój sposób myślenia. Na koniec w tagu np. <ulica>Adama Mickiewicza</ulica> lub <ulica>Kwiatowa</ulica> wpisz odpowiedź.
PROMPT;
    private const string USER_PROMPT = <<<PROMPT
{transkrypcje}
PROMPT;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly FFMpeg $ffmpeg,
        private readonly Omniparse $omniparse,
        private readonly AIChatRequestHandlerInterface $chatRequestHandler,
        private readonly ResultSender $resultSender,
        private readonly AiResponseParser $aiResponseParser,

        #[Autowire(env: 'CENTRALA_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,

        #[Autowire(env: 'TASK6_SOURCE_FILE_URL')]
        private readonly string $sourceFileUrl,

        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $kernelDir,

        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        [$bar1, $bar2] = $this->setupBars($output);

        $zipDir = $this->downloadAndUnzip();
        $files = glob("{$zipDir}/*");

        $bar2?->setMaxSteps(count($files));
        $bar2?->setProgress(0);
        $bar2?->advance(0);
        $bar1->setMessage('Converting the files to MP3.');
        $bar1->advance();

        $mp3Paths = $this->convertToMp3($zipDir, $files, $bar2);

        $bar2?->setProgress(0);
        $bar2?->advance(0);
        $bar1->setMessage('Transcribing the MP3 files.');
        $bar1->advance();

        $transcribed = $this->transcript($mp3Paths, $bar2);

        $bar2?->finish();
        $bar1->finish();

        $output->writeln('Looking for the answer...');

        $messages = ChatPromptTemplate::create(
            new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::SYSTEM_PROMPT),
            new AIChatMessage(AIChatMessageRoleEnum::USER, self::USER_PROMPT),
        )->format([
            'transkrypcje' => implode("\n\n", array_map(static fn(string $content): string => "<transkrypcja>{$content}</transkrypcja>", $transcribed))
        ]);

        $response = $this->chatRequestHandler
            ->createRequest(...$messages)
            ->addCriteria(PrivacyCriteria::MEDIUM)
            ->addCriteria(CapabilityCriteria::ADVANCED)
            ->addCriteria(FeatureCriteria::TEXT_GENERATION)
            ->addOptions([
                'temperature' => 0.4
            ])
            ->build()
            ->execute()
            ->getMessage()
            ->content;

        /*
         * In my case response was:
         * Na podstawie transkrypcji można wywnioskować, że Andrzej Maj jest wykładowcą w Krakowie. Jeden ze świadków wspomina, że Andrzej chciał pracować na "królewskiej uczelni" i że osiągnął swój cel. Inny świadek mówi, że Andrzej pracował na wydziale czy instytucie informatyki i matematyki komputerowej w Krakowie. Kolejny świadek wspomina o ulicy od matematyka, co wpada w komendanta, co sugeruje, że instytut może znajdować się na ulicy związanej z matematyką.
         * W Krakowie znajduje się Uniwersytet Jagielloński, który jest jedną z najstarszych i najbardziej prestiżowych uczelni w Polsce, często nazywany "królewską uczelnią". Wydział Matematyki i Informatyki Uniwersytetu Jagiellońskiego znajduje się przy ulicy Profesora Stanisława Łojasiewicza. Profesor Łojasiewicz był znanym matematykiem, co pasuje do wskazówki dotyczącej ulicy od matematyka.
         * Na podstawie tych informacji można przypuszczać, że instytut, w którym wykłada Andrzej Maj, znajduje się na ulicy Profesora Stanisława Łojasiewicza.
         * <ulica>Profesora Stanisława Łojasiewicza</ulica>
         * Odpowiedź to: Profesora Stanisława Łojasiewicza
         */

        $output->writeln($response);

        $street = $this->aiResponseParser->fetchOneByTag($response, 'ulica');

        if ($street === null) {
            $output->writeln('Nie udało się odnaleźć odpowiedzi.');
            return Command::FAILURE;
        }

        $output->writeln("Odpowiedź to: {$street}");

        dump($this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'mp3', $response));

        return Command::SUCCESS;
    }

    /**
     * @return array<ProgressBar|null>
     */
    private function setupBars(OutputInterface $output): array
    {
        $section1 = $output instanceof ConsoleOutputInterface ? $output->section() : $output;
        $section2 = $output instanceof ConsoleOutputInterface ? $output->section() : null;

        $bar1 = new ProgressBar($section1, 3);
        $bar1->setMessage('Downloading and extracting the ZIP file.');
        $bar1->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $bar1->start();

        $bar2 = $section2 === null ? null : new ProgressBar($section2, 1);
        $bar2?->start();

        return [$bar1, $bar2];
    }

    private function downloadAndUnzip(): string
    {
        $zipDir = "{$this->kernelDir}/var/task6/zip";

        if (file_exists($zipDir)) {
            return $zipDir;
        }

        if (!file_exists($zipDir) && !mkdir($zipDir, recursive: true) && !is_dir($zipDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $zipDir));
        }

        (new ZipFile())
            ->openFromStream($this->httpClient->request('GET', $this->sourceFileUrl)->toStream())
            ->extractTo($zipDir);

        return $zipDir;
    }

    private function convertToMp3(string $zipDir, array $files, ?ProgressBar $bar2): array
    {
        $mp3Dir = "{$this->kernelDir}/var/task6/mp3";

        if (file_exists($mp3Dir)) {
            return glob("{$mp3Dir}/*");
        }

        if (!file_exists($mp3Dir) && !mkdir($mp3Dir, recursive: true) && !is_dir($mp3Dir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $mp3Dir));
        }

        $mp3Paths = [];
        foreach ($files as $path) {
            $fileName = substr($path, 1 + strlen($zipDir));
            $mp3Paths[] = $mp3Path = "{$mp3Dir}/{$fileName}.mp3";
            $this->ffmpeg->open($path)->save(new Mp3, $mp3Path);
            $bar2?->advance();
        }

        return $mp3Paths;
    }

    private function transcript(array $mp3Paths, ?ProgressBar $bar2): array
    {
        $pathToName = static function (string $path): string {
            $parts = explode('/', $path);
            return end($parts);
        };

        $mdDir = "{$this->kernelDir}/var/task6/md";

        if (file_exists($mdDir)) {
            $files = glob("{$mdDir}/*");

            return array_combine(
                array_map($pathToName, $files),
                array_map(static fn(string $path): string => file_get_contents($path), $files)
            );
        }

        if (!file_exists($mdDir) && !mkdir($mdDir, recursive: true) && !is_dir($mdDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $mdDir));
        }

        $transcriptions = [];

        foreach ($mp3Paths as $path) {
            $transcription = $this->omniparse->toMd(new SplFileInfo($path));
            $name = $pathToName($path);

            file_put_contents("{$mdDir}/{$name}.md", $transcription);
            $transcriptions[$pathToName($path)] = $transcription;
            $bar2?->advance();
        }

        return $transcriptions;
    }
}
