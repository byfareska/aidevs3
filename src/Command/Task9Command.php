<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\ResultSender;
use App\Modelflow\FeatureCriteria;
use App\Service\AiResponseParser;
use App\Service\Omniparse;
use Intervention\Image\Interfaces\ImageManagerInterface;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\Chat\Request\Message\ImageBase64Part;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use PhpZip\ZipFile;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'task:9', description: 'Task 9: 2024-11-14')]
final class Task9Command extends TaskSolution
{
    private const string CATEGORIZE_PROMPT = <<<PROMPT
Categorize it to one of the following categories,
if it meets any of positive criteria and doesn't meet any negative criteria:
<ruleset>
    <category>people</category>
    <positive-criteria>the reason to write the note was information about human or person was the reason for the text. If it has first name or surname it's an human</positive-criteria>
    <negative-criteria>Don't categorize animals to this category</negative-criteria>
</ruleset>
<ruleset>
    <category>hardware</category>
    <positive-criteria>robots or devices</positive-criteria>
    <positive-criteria>the note is about fixing hardware</positive-criteria>
    <positive-criteria>the note is about hardware failure</positive-criteria>
    <negative-criteria>the note is about my robot's software or his own parts</negative-criteria>
    <negative-criteria>the note is about false alarm or nothing meaningful has been detected</negative-criteria>
    <negative-criteria>the note is about my robot's routine activities that didn't cause any extra action</negative-criteria>
</ruleset>
<ruleset>
    <category>other</category>
    <positive-citeria>No organic activity detected</positive-citeria>
    <positive-citeria>No activity detected</positive-citeria>
    <positive-citeria>the note is about software update</positive-citeria>
    <positive-criteria>if it's about something else than category people or hardware</positive-criteria>
    <positive-criteria>the reason of note was an animal activity</positive-criteria>
    <positive-criteria>doesn't match to hardware or people category</positive-criteria>
</ruleset>

Write your decision-making in <thinking> tag.
Reply MUST CONTAIN the final decision with one of these options, the category must be wrapped in <category> tag:
<category>people</category>
<category>hardware</category>
<category>other</category>
PROMPT;


    private const string TEXT_SYSTEM_PROMPT_CATEGORIZE = <<<PROMPT
I need to categorize notes of my robot. Please write your conclusions from received text.

PROMPT. self::CATEGORIZE_PROMPT;

    private const string IMAGE_SYSTEM_PROMPT_CATEGORIZE = <<<PROMPT
I've described what you can see on the picture. Please write your conclusions from received text.

PROMPT. self::CATEGORIZE_PROMPT;

    private const string IMAGE_SYSTEM_PROMPT_IS_TEXT1 = <<<PROMPT
You will receive an image. Describe in tag what you see on the image.
PROMPT;

    private const string IMAGE_SYSTEM_PROMPT_IS_TEXT2 = <<<PROMPT
I've described what you can see on the picture.
Basing on the description, categorize the image to exactly 1 of the following categories, reply with category wrapped in <object> tag:
<object>digital document</object>
<object>note</object>
<object>text</object>
<object>printed document</object>
<object>other</object>
PROMPT;

    private const string IMAGE_SYSTEM_PROMPT_DESCRIBE = <<<PROMPT
You will receive an image.
Describe what you see in the image, if you see any text, write it down, if you see any objects, describe them.
Be as detailed as possible.
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
        private readonly AiResponseParser $aiResponseParser,
        private readonly AIChatRequestHandlerInterface $requestHandler,
        private readonly ImageManagerInterface $imageManager,
        private readonly Omniparse $omniparse,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $zipDir = $this->downloadAndUnzip();
        /** @var array<string, string> $files */
        $files = [...$this->listMeaningfulFiles($zipDir)];

        $categorized = [];
        foreach ($files as $filePath => $kind) {
            $fileName = (new SplFileInfo($filePath))->getFilename();

            if ($fileName === '2024-11-12_report-99') {
                continue;
            }

            $category = match ($kind) {
                'text' => $this->categorizeText(file_get_contents($filePath)),
                'image' => $this->categorizeImage($filePath),
                'audio' => $this->categorizeAudio($filePath),
                default => 'unsupported',
            };

            $categorized[$category][] = $fileName;
            $output->writeln("File: {$fileName} has been categorized as: {$category}");
        }

        $result = $this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'kategorie', [
            'people' => $categorized['people'] ?? [],
            'hardware' => $categorized['hardware'] ?? [],
        ]);

        dump($result);

        return Command::SUCCESS;
    }


    private function downloadAndUnzip(): string
    {
        $zipDir = "{$this->projectDir}/var/task9/zip";

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

    private function listMeaningfulFiles(string $zipDir): iterable
    {
        foreach (glob("{$zipDir}/*") as $filePath) {
            match ($kind = explode('/', mime_content_type($filePath))[0]) {
                'audio', 'text', 'image' => yield $filePath => $kind,
                default => null,
            };
        }
    }

    private function categorizeText(string $text): string
    {
        $messages = [
            new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::TEXT_SYSTEM_PROMPT_CATEGORIZE),
            new AIChatMessage(AIChatMessageRoleEnum::USER, $text)
        ];

        $content = $this->requestHandler
            ->createRequest(...$messages)
            ->addCriteria(PrivacyCriteria::LOW)
            ->addCriteria(CapabilityCriteria::BASIC)
            ->addCriteria(FeatureCriteria::TEXT_GENERATION)
            ->build()
            ->execute()
            ->getMessage()
            ->content;

        return $this->aiResponseParser->fetchOneByTag($content, 'category');
    }

    private function categorizeImage(string $filePath): string
    {
        $imageMessage = new AIChatMessage(AIChatMessageRoleEnum::USER,
            new ImageBase64Part(
                base64_encode((string)$this->imageManager->read($filePath)->toJpeg()),
                'image/jpeg'
            ));

        $type1 = $this->requestHandler
            ->createRequest(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::IMAGE_SYSTEM_PROMPT_IS_TEXT1),
                $imageMessage
            )
            ->addCriteria([FeatureCriteria::IMAGE_VISION, PrivacyCriteria::LOW, CapabilityCriteria::BASIC])
            ->build()
            ->execute()
            ->getMessage()
            ->content;

        $type2 = $this->requestHandler
            ->createRequest(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::IMAGE_SYSTEM_PROMPT_IS_TEXT2),
                new AIChatMessage(AIChatMessageRoleEnum::USER, $type1),
            )
            ->addCriteria([FeatureCriteria::IMAGE_VISION, PrivacyCriteria::LOW, CapabilityCriteria::BASIC])
            ->build()
            ->execute()
            ->getMessage()
            ->content;

        $types = $this->aiResponseParser->fetchByTag($type2, 'object');

        if (!in_array('other', $types)) {
            return $this->categorizeText($this->omniparse->toMd(new SplFileInfo($filePath), mime_content_type($filePath)));
        }

        $description = $this->requestHandler
            ->createRequest(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::IMAGE_SYSTEM_PROMPT_DESCRIBE),
                $imageMessage
            )
            ->addCriteria(PrivacyCriteria::LOW)
            ->addCriteria(CapabilityCriteria::BASIC)
            ->addCriteria(FeatureCriteria::IMAGE_VISION)
            ->build()
            ->execute()
            ->getMessage()
            ->content;

        $categories = $this->requestHandler
            ->createRequest(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::IMAGE_SYSTEM_PROMPT_CATEGORIZE),
                new AIChatMessage(AIChatMessageRoleEnum::USER, $description)
            )
            ->addCriteria(PrivacyCriteria::LOW)
            ->addCriteria(CapabilityCriteria::BASIC)
            ->addCriteria(FeatureCriteria::TEXT_GENERATION)
            ->build()
            ->execute()
            ->getMessage()
            ->content;

        return $this->aiResponseParser->fetchOneByTag($categories, 'category');
    }

    private function categorizeAudio(string $filePath): string
    {
        return $this->categorizeText($this->omniparse->toMd(new SplFileInfo($filePath)));
    }
}