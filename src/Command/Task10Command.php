<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\ResultSender;
use App\Factory\EmbeddingsStoreFactory;
use App\Modelflow\CrudEmbeddingsStoreInterface;
use App\Modelflow\FeatureCriteria;
use App\Modelflow\Task10Embedding;
use App\Service\AiResponseParser;
use App\Service\Omniparse;
use App\Service\TemporaryFileManager;
use League\HTMLToMarkdown\HtmlConverter;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\Chat\Request\Message\ImageBase64Part;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use ModelflowAi\Embeddings\Adapter\EmbeddingAdapterInterface;
use ModelflowAi\Embeddings\Generator\EmbeddingGeneratorInterface;
use ModelflowAi\Embeddings\Model\EmbeddingInterface;
use ModelflowAi\PromptTemplate\ChatPromptTemplate;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\UriResolver;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[AsCommand(name: 'task:10', description: 'Task 10: 2024-11-15')]
final class Task10Command extends TaskSolution
{
    private const string OPTION_NO_CACHE = 'no-cache';

    private const string ANSWER_SYSTEM_PROMPT = <<<PROMPT
You will receive a question wrapped in question tag. 
I've swapped images with it's descriptions and audios with their transcriptions. Keep in mind that embedded content is relevant.
The answer probably is in image description or audio transcription.
Instead of using "hot cake" phrase, use "pizza" phrase.
Answer to the question
PROMPT;


    private const string ANSWER_USER_PROMPT = <<<PROMPT
{fragments}
<question>{question}</question>
PROMPT;
    private const string CATEGORIZE_SYSTEM_PROMPT = <<<PROMPT
Reply with 2 phases:

1. Pick the main object of image, nothing else. Wrap the object in <object> tag. eg. <object>car</object>

2. Reply with one of the categories or "other" if the image does not fit any of the categories.
Categorize the object from phase 1 to one of following categories:
- place if image shows city, market square, street, etc.
- food if image shows food, dish, fruit, vegetables, meat etc.
YOU HAVE TO wrap the category in <category> tag, eg <category>place</category> <category>other</category>.
PROMPT;
    private const string IMAGE_DESCRIPTION_SYSTEM_PROMPT = <<<PROMPT
Describe the image.
PROMPT;
    private const string IMAGE_DESCRIPTION_PLACE_SYSTEM_PROMPT = <<<PROMPT
You are geoguessr champion! You have to guess where the photo was taken. Name the city.
PROMPT;
    private const string IMAGE_DESCRIPTION_PLACE_USER_PROMPT = <<<PROMPT
The photo description is "{description}". The annotation says "{caption}".
PROMPT;

    public function __construct(
        #[Autowire(env: 'TASK10_ARTICLE_URL')]
        private readonly string $articleUrl,

        #[Autowire(env: 'TASK10_QUESTIONS_URL')]
        private readonly string $questionsUrl,

        #[Autowire(env: 'CENTRALA_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,

        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,

        private readonly HttpClientInterface $httpClient,
        private readonly HtmlConverter $html2md,
        private readonly AIChatRequestHandlerInterface $requestHandler,
        private readonly Omniparse $omniparse,
        private readonly TemporaryFileManager $tmpManager,
        private readonly EmbeddingGeneratorInterface $embeddingGenerator,
        private readonly EmbeddingsStoreFactory $embeddingsStoreFactory,
        private readonly EmbeddingAdapterInterface $embeddingAdapter,
        private readonly ResultSender $resultSender,
        private readonly AiResponseParser $responseParser,
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
        if (!is_dir($concurrentDirectory = $this->projectDir . '/var/task10') && !mkdir($concurrentDirectory, 0777, true) && !is_dir($concurrentDirectory)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }

        $splittedFilePath = $this->projectDir . '/var/task10/splitted.json';
        $store = $this->createStore();

        if ($input->getOption(self::OPTION_NO_CACHE)) {
            $output->writeln('Removing cache...');
            if ($store->exists()) {
                $store->remove();
            }

            if (file_exists($splittedFilePath)) {
                unlink($splittedFilePath);
            }
        }

        $isSplitted = file_exists($splittedFilePath);
        $sectionsMd = $isSplitted
            ? json_decode(file_get_contents($splittedFilePath), false, 512, JSON_THROW_ON_ERROR)
            : [...$this->articleSplittedByHeaders($output)];

        if (!$isSplitted) {
            file_put_contents($splittedFilePath, json_encode($sectionsMd, JSON_THROW_ON_ERROR));
        }

        $this->createEmbeddingsStore($output, $store, $sectionsMd);
        $output->writeln('The questions are:');
        $answers = [];
        foreach ($this->fetchQuestions() as $questionId => $question) {
            $output->write("{$question} ");
            $vector = $this->embeddingAdapter->embedText($question);
            $fragments = array_map(
                static fn(EmbeddingInterface $embedding) => $embedding->getContent(),
                $store->similaritySearch($vector, 3)
            );
            $answers[str_pad((string)$questionId, 2, '0', STR_PAD_LEFT)] = $answer = $this->createAnswer($question, $fragments);
            $output->writeln($answer);
        }

        $result = $this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'arxiv', $answers);

        dump($result);

        return Command::SUCCESS;
    }

    private function articleSplittedByHeaders(OutputInterface $output): iterable
    {
        $output->writeln('Fetching the website...');
        $article = $this->httpClient->request('GET', $this->articleUrl)->getContent();
        $crawler = new Crawler($article);
        $body = $crawler->filter('body')->html();
        $simplifiedBody = strip_tags($body, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'em', 'del', 'code', 'img', 'audio', 'source']);

        // Group headers with their corresponding content
        $sections = [];
        $buffer = '';
        $currentHeader = null;


        $output->writeln('Splitting by headers...');
        foreach (preg_split('/(<h[1-6][^>]*>.*?<\/h[1-6]>)/i', $simplifiedBody, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $section) {
            if (preg_match('/<h[1-6][^>]*>.*?<\/h[1-6]>/', $section)) {
                $currentHeader = $section;

                if (!empty($buffer)) {
                    $sections[] = $buffer;
                }

                $buffer = $currentHeader;
            } elseif ($currentHeader) {
                $buffer .= $section;
            }
        }

        $sections[] = $buffer;


        $output->writeln('Replacing images with descriptions and audios with transcriptions...');
        foreach ($sections as $section) {
            preg_match_all('/<img[^>]*src=["\']([^"\']+)["\'].*>/Ui', $section, $matches);
            foreach ($matches[1] as $i => $imgSrc) {
                $caption = $this->findCaption($section, $matches[0][$i]);
                $imgUri = UriResolver::resolve($imgSrc, $this->articleUrl);
                $imgResponse = $this->httpClient->request('GET', $imgUri);

                $output->writeln("Describing {$imgUri}");
                $description = $this->describeImage($imgResponse, $caption);
                $section = str_replace($matches[0][$i], $description, $section);
            }

            preg_match_all('/<audio[^>]*>.*?<source\s+src="([^"]+)"[^>]*>.*?<\/audio>/is', $section, $matches);

            foreach ($matches[1] as $i => $audioSrc) {
                $audioUri = UriResolver::resolve($audioSrc, $this->articleUrl);
                $output->writeln("Transcribing {$audioUri}");
                $audioResponse = $this->httpClient->request('GET', $audioUri);
                $tmpFileName = $this->tmpManager->createFilePath();
                $fileHandler = fopen($tmpFileName, 'wb+');
                foreach ($this->httpClient->stream($audioResponse) as $chunk) {
                    fwrite($fileHandler, $chunk->getContent());
                }
                fclose($fileHandler);
                $section = str_replace($matches[0][$i], $this->omniparse->toMd(new SplFileInfo($tmpFileName), $audioResponse->getHeaders()['content-type'][0]), $section);
            }

            yield $this->html2md->convert($section);
        }
    }

    private function createEmbeddingsStore(OutputInterface $output, CrudEmbeddingsStoreInterface $store, array $sectionsMd): CrudEmbeddingsStoreInterface
    {
        if ($store->exists()) {
            $output->writeln('Store already exists, using it...');

            return $store;
        }

        $output->writeln('Generating embeddings...');
        $store->create();
        $embeddings = $this->embeddingGenerator->generateEmbeddings(array_map(
            static fn(string $section) => new Task10Embedding($section),
            $sectionsMd
        ));
        $store->addDocuments($embeddings);
        sleep(10);
        return $store;
    }

    private function createStore(): CrudEmbeddingsStoreInterface
    {
        $store = $this->embeddingsStoreFactory->create('task10');

        if (!$store->exists()) {
            $store->create();
        }

        return $store;
    }

    /**
     * @return array<int, string> question ID to question text
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     * @throws TransportExceptionInterface
     */
    private function fetchQuestions(): array
    {
        $response = $this->httpClient->request('GET', str_replace('%api-key%', $this->resultSender->getApiKey(), $this->questionsUrl));
        $lines = preg_split('/\r\n|\r|\n/', $response->getContent());
        $questions = [];

        foreach ($lines as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }
            $parts = explode('=', $line, 2);
            $questions[(int)$parts[0]] = $parts[1];
        }

        return $questions;
    }

    private function createAnswer(string $question, array $fragments): string
    {
        return $this->requestHandler
            ->createRequest(...ChatPromptTemplate::create(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::ANSWER_SYSTEM_PROMPT),
                new AIChatMessage(AIChatMessageRoleEnum::USER, self::ANSWER_USER_PROMPT)
            )->format([
                'fragments' => implode("\n", array_map(static fn(string $f): string => "<fragment>\n{$f}\n</fragment>", $fragments)),
                'question' => $question
            ]))
            ->addCriteria(CapabilityCriteria::INTERMEDIATE)
            ->addCriteria(FeatureCriteria::TEXT_GENERATION)
            ->build()
            ->execute()
            ->getMessage()
            ->content;
    }

    private function findCaption(string $html, string $imgElement): string
    {
        $exploded = explode($imgElement, $html);
        foreach (explode("\n", $exploded[1] ?? '') as $line) {
            $line = trim($line);
            if (!empty($line)) {
                return $line;
            }
        }

        return '';
    }

    private function describeImage(ResponseInterface $imgResponse, string $caption): string
    {
        $description = $this->requestHandler
            ->createRequest(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::IMAGE_DESCRIPTION_SYSTEM_PROMPT),
                new AIChatMessage(AIChatMessageRoleEnum::USER,
                    new ImageBase64Part(base64_encode($imgResponse->getContent()), $imgResponse->getHeaders()['content-type'][0])
                )
            )
            ->addCriteria(PrivacyCriteria::LOW)
            ->addCriteria(CapabilityCriteria::BASIC)
            ->addCriteria(FeatureCriteria::IMAGE_VISION)
            ->addOptions(['temperature' => 0.5])
            ->build()
            ->execute()
            ->getMessage()
            ->content;

        $categoryResponse = $this->requestHandler
            ->createRequest(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::CATEGORIZE_SYSTEM_PROMPT),
                new AIChatMessage(AIChatMessageRoleEnum::USER, $description),
            )
            ->addCriteria(PrivacyCriteria::LOW)
            ->addCriteria(CapabilityCriteria::BASIC)
            ->addCriteria(FeatureCriteria::TEXT_GENERATION)
            ->addOptions(['temperature' => 0.5])
            ->build()
            ->execute()
            ->getMessage()
            ->content;

        if (strtolower(trim($this->responseParser->fetchOneByTag($categoryResponse, 'category'))) === 'place') {
            $result = $this->requestHandler
                ->createRequest(
                    ...ChatPromptTemplate::create(
                        new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::IMAGE_DESCRIPTION_PLACE_SYSTEM_PROMPT),
                        new AIChatMessage(AIChatMessageRoleEnum::USER, self::IMAGE_DESCRIPTION_PLACE_USER_PROMPT),
                    )->format([
                        'description' => $description,
                        'caption' => $caption
                    ])
                )
                ->addCriteria(PrivacyCriteria::LOW)
                ->addCriteria(CapabilityCriteria::BASIC)
                ->addCriteria(FeatureCriteria::TEXT_GENERATION)
                ->addOptions(['temperature' => 0.5])
                ->build()
                ->execute()
                ->getMessage()
                ->content;

            return "{$description}\n{$result}";
        }

        return $description;
    }
}