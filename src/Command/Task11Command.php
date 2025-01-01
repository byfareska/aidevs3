<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\ResultSender;
use App\Modelflow\FeatureCriteria;
use App\Service\AiResponseParser;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use ModelflowAi\PromptTemplate\ChatPromptTemplate;
use OpenAI\Exceptions\ErrorException;
use PhpZip\ZipFile;
use RuntimeException;
use SplFileObject;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'task:11', description: 'Task 11: 2024-11-18')]
final class Task11Command extends TaskSolution
{
    private const string SYSTEM_PROMPT = <<<PROMPT
Otrzymasz dokument w tagu <dokument> oraz fakty w tagu <fakty>.
Wypisz kilka tagów dla dokumentu, jeśli dokument dotyczy czegoś, co jest opisane w faktach, uwzględnij fakt przy generowaniu tagów.
Każdy tag wypisz w tagu <tag> oraz musi być w mianowniku liczby pojedynczej np. <tag>Sportowiec</tag> <tag>Waleczność</tag> <tag>Film</tag>.

Jeśli wspomniane jest coś z poniższej listy w opisywanym tekście, musisz dodać tag mówiący o:
- profesja osoby (wypisz tag z nazwą profesji np. <tag>Lekarz</tag>)
- nazwa osoby (wypisz tag z imieniem i nazwiskiem np. <tag>Jan Kowalski</tag>)
- umiejętności techniczne osoby, jeśli jest to język programowania, to wypisz tag "Programista nazwa języka" np. <tag>Programista C++</tag>
- zwierzęta

Wypisz 3-20 tagów.
PROMPT;

    private const string USER_PROMPT = <<<PROMPT
<fakty>
{facts}
</fakty>
<dokument>
{document}
</dokument>
PROMPT;


    public function __construct(
        #[Autowire(env: 'TASK9_ENDPOINT')]
        private readonly string $sourceFileUrl,

        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,

        #[Autowire(env: 'CENTRALA_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,

        private readonly ResultSender $resultSender,
        private readonly HttpClientInterface $httpClient,
        private readonly AIChatRequestHandlerInterface $requestHandler,
        private readonly AiResponseParser $responseParser,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $zipDir = $this->downloadAndUnzip();
        $facts = $this->buildFactsList($zipDir);
        $fileNameToContent = $this->buildFileToContent($zipDir);
        $fileNameToTags = [];

        foreach ($fileNameToContent as $fileName => $content) {
            $result = null;

            while ($result === null) {
                try {
                    $result = $this->requestHandler
                        ->createRequest(...ChatPromptTemplate::create(
                            new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::SYSTEM_PROMPT),
                            new AIChatMessage(AIChatMessageRoleEnum::USER, self::USER_PROMPT),
                        )->format([
                            'facts' => implode("\n", $facts),
                            'document' => $content,
                        ]))
                        ->addCriteria(PrivacyCriteria::LOW)
                        ->addCriteria(CapabilityCriteria::ADVANCED)
                        ->addCriteria(FeatureCriteria::TEXT_GENERATION)
                        ->addOptions(['temperature' => 1])
                        ->build()
                        ->execute()
                        ->getMessage()
                        ->content;
                } catch (ErrorException $e) {
                    if (str_starts_with($e->getMessage(), 'Rate limit reached')) {
                        sleep(10);
                        $output->writeln('Rate limited... sleeping for 10 seconds');
                    } else {
                        throw $e;
                    }
                }
            }

            $tags = [
                ...$this->responseParser->fetchByTag($result, 'tag'),
                'Sektor ' . substr($fileName, -6, 2),
            ];

            $fileNameToTags[$fileName] = $joinedTags = implode(', ', $tags);

            $output->writeln("Tags for {$fileName}: {$joinedTags}");
        }

        $flag = $this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'dokumenty', $fileNameToTags);

        dump($flag);

        return Command::SUCCESS;
    }

    private function downloadAndUnzip(): string
    {
        $zipDir = "{$this->projectDir}/var/task11/zip";

        if (file_exists($zipDir)) {
            return $zipDir;
        }

        if (!file_exists($zipDir) && !mkdir($zipDir, recursive: true) && !is_dir($zipDir)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $zipDir));
        }

        new ZipFile()
            ->openFromStream($this->httpClient->request('GET', $this->sourceFileUrl)->toStream())
            ->extractTo($zipDir);

        return $zipDir;
    }

    private function buildFactsList(string $zipDir): array
    {
        $facts = [];

        foreach (glob("{$zipDir}/facts/*.txt") as $fileName) {
            $fileContents = file_get_contents($fileName);
            array_push($facts, ...preg_split('/\r\n|\r|\n/', $fileContents));
        }

        $facts = array_map('trim', $facts);
        $filter = static fn(string $fact): bool => !empty($fact) && strtolower($fact) !== 'entry deleted';

        return array_filter($facts, $filter);
    }

    private function buildFileToContent(string $zipDir): array
    {
        $nameToContent = [];

        foreach (glob("{$zipDir}/*.txt") as $fileName) {
            $nameToContent[new SplFileObject($fileName)->getFilename()] = file_get_contents($fileName);
        }

        return $nameToContent;
    }
}
