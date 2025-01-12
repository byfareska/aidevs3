<?php declare(strict_types=1);

namespace App\Command;

use App\AiDevs\ResultSender;
use App\Modelflow\FeatureCriteria;
use App\Modelflow\ProviderCriteria;
use App\Service\AiResponseParser;
use App\Service\LinkFinder;
use DateInterval;
use ModelflowAi\Chat\AIChatRequestHandlerInterface;
use ModelflowAi\Chat\Request\Message\AIChatMessage;
use ModelflowAi\Chat\Request\Message\AIChatMessageRoleEnum;
use ModelflowAi\Chat\Request\Message\ImageBase64Part;
use ModelflowAi\DecisionTree\Criteria\CapabilityCriteria;
use ModelflowAi\DecisionTree\Criteria\PrivacyCriteria;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DomCrawler\UriResolver;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(name: 'task:16', description: 'Task 16: 2024-11-25')]
final class Task16Command extends TaskSolution
{

    private const string CATEGORIZE_SYSTEM_PROMPT = <<<PROMPT
You will receive a photo. Describe what you can see in the photo, then categorize it to one of following groups:
- <group>DEFECT</group>if photo contains a defects or glitches: e.g. visually distorted, resembling a digital glitch, wavy dots, etc.
- <group>BRIGHT</group> if photo is too bright so it's hard to see details
- <group>DARK</group> if photo is too dark so it's hard to see details
- <group>NONE</group> if photo is clear from above defects
PROMPT;
    private const string PERSON_SYSTEM_PROMPT = <<<PROMPT
You will receive a photo. If there is one person in the photo, describe the person otherwise (if 0 or more than 1 person) reply with "<error>NOT A SINGLE PERSON</error>" nothing more.
If image shows a single person, describe the person in the photo. Describe as much as possible about the person, especially:
- estimated age
- special signs
- tattoos what they are and where they are
- hair color and length
The description must be in Polish language.
PROMPT;
    private const string MERGE_SYSTEM_PROMPT = <<<PROMPT
You will receive a few descriptions of the same person. Merge them into one coherent description.
Keep as much as possible information about the person, especially:
- estimated age
- special signs
- tattoos what they are and where they are
- hair color and length
The description must be in Polish language.
PROMPT;


    public function __construct(
        #[Autowire(env: 'CENTRALA_VERIFY_ENDPOINT')]
        private readonly string $endpointVerify,

        private readonly ResultSender $resultSender,
        private readonly LinkFinder $linkFinder,
        private readonly CacheInterface $cache,
        private readonly AiResponseParser $responseParser,
        private readonly HttpClientInterface $httpClient,
        private readonly AIChatRequestHandlerInterface $requestHandler,
        ?string $name = null
    )
    {
        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $images = $this->executeCommand($output, 'START');
        $usableImages = [...$this->createUsableImagesList($output, $images)];

        $output->writeln("\n__________\n\nUsable, non-defective images:");
        foreach ($usableImages as $image) {
            $output->writeln(" - {$image}");
        }
        $output->writeln("\n__________\n\n");

        $descriptions = [];

        foreach ($usableImages as $image) {
            $output->writeln("Describing person {$image}");
            $description = $this->describePerson($image);

            if (strtoupper($this->responseParser->fetchOneByTag($description, 'error') ?? '') !== 'NOT A SINGLE PERSON') {
                $descriptions[] = $description;
            }

            $output->writeln("{$description}\n\n");
        }

        $output->writeln("__________\n\nFinal description:");
        $mergedDescription = $this->mergeDescriptions($descriptions);
        $output->writeln($mergedDescription);

        $result = $this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'photos', $mergedDescription);
        dump($result);

        return Command::SUCCESS;
    }


    private function executeCommand(OutputInterface $output, string $command, ?string $requestedFrom = null): array
    {
        $output->write("{$command}: ");
        $message = $this->resultSender->sendAndDecodeJsonBody($this->endpointVerify, 'photos', $command)->message;
        $output->writeln($message);

        return $this->findImages($message, $requestedFrom);
    }

    /**
     * @return iterable<string>
     */
    private function createUsableImagesList(OutputInterface $output, array $images, int &$step = 0): iterable
    {
        if (++$step > 10) {
            throw new RuntimeException('Too many iterations');
        }

        foreach ($images as $image) {
            $category = $this->categorizeImage($image);
            $output->writeln("{$image}: {$category}");

            yield from match (strtoupper($category)) {
                'DEFECT' => $this->createUsableImagesList($output, $this->executeCommand($output, 'REPAIR ' . basename($image), $image), $step),
                'DARK' => $this->createUsableImagesList($output, $this->executeCommand($output, 'BRIGHTEN ' . basename($image), $image), $step),
                'BRIGHT' => $this->createUsableImagesList($output, $this->executeCommand($output, 'DARKEN ' . basename($image), $image), $step),
                default => [$image],
            };
        }
    }

    private function categorizeImage(string $imageLink): string
    {
        return $this->cache->get('task16_cat_image_' . sha1($imageLink), function (ItemInterface $item) use (&$imageLink): string {
            $item->expiresAfter(new DateInterval('P1Y'));
            $imgResponse = $this->httpClient->request('GET', $imageLink);
            $description = $this->requestHandler
                ->createRequest(
                    new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::CATEGORIZE_SYSTEM_PROMPT),
                    new AIChatMessage(AIChatMessageRoleEnum::USER,
                        new ImageBase64Part(base64_encode($imgResponse->getContent()), $imgResponse->getHeaders()['content-type'][0])
                    )
                )
                ->addCriteria(PrivacyCriteria::LOW)
                ->addCriteria(CapabilityCriteria::ADVANCED)
                ->addCriteria(FeatureCriteria::IMAGE_VISION)
                ->addCriteria(ProviderCriteria::GOOGLE) // As always, Microsoft's product is not working. gpt-4o has lost image vision capabilities (xD), so I've used Google's API.
                ->addOptions(['temperature' => 0.5])
                ->build()
                ->execute()
                ->getMessage()
                ->content;

            return $this->responseParser->fetchOneByTag($description, 'group');
        });
    }

    private function describePerson(string $imageLink): string
    {
        return $this->cache->get('task16_cat_person_' . sha1($imageLink), function (ItemInterface $item) use (&$imageLink): string {
            $item->expiresAfter(new DateInterval('P1Y'));
            $imgResponse = $this->httpClient->request('GET', $imageLink);

            return $this->requestHandler
                ->createRequest(
                    new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::PERSON_SYSTEM_PROMPT),
                    new AIChatMessage(AIChatMessageRoleEnum::USER,
                        new ImageBase64Part(base64_encode($imgResponse->getContent()), $imgResponse->getHeaders()['content-type'][0])
                    )
                )
                ->addCriteria(PrivacyCriteria::LOW)
                ->addCriteria(CapabilityCriteria::ADVANCED)
                ->addCriteria(FeatureCriteria::IMAGE_VISION)
                ->addCriteria(ProviderCriteria::GOOGLE)
                ->addOptions(['temperature' => 0.5])
                ->build()
                ->execute()
                ->getMessage()
                ->content;
        });
    }

    private function mergeDescriptions(array $descriptions): string
    {
        return $this->requestHandler
            ->createRequest(
                new AIChatMessage(AIChatMessageRoleEnum::SYSTEM, self::MERGE_SYSTEM_PROMPT),
                new AIChatMessage(AIChatMessageRoleEnum::USER, implode("\n", array_map(static fn(string $d) => "<description>\n{$d}\n</description>", $descriptions)))
            )
            ->addCriteria(PrivacyCriteria::LOW)
            ->addCriteria(CapabilityCriteria::ADVANCED)
            ->addCriteria(FeatureCriteria::TEXT_GENERATION)
            ->addCriteria(ProviderCriteria::GOOGLE)
            ->addOptions(['temperature' => 0.5])
            ->build()
            ->execute()
            ->getMessage()
            ->content;
    }

    private function findImages(string $message, ?string $fallbackBaseLink = null): array
    {
        $links = $this->linkFinder->findLinks($message);

        if (!isset($links[1]) && str_ends_with($links[0] ?? '', '/')) { // received a directory link + file names
            $baseLink = $links[0];
            $links = $this->linkFinder->findFileNames($message, ['png', 'jpg', 'jpeg']);

            return array_map(static fn(string $link) => UriResolver::resolve($link, $baseLink), $links);
        }

        return empty($links)
            ? array_map(
                static fn(string $link) => $fallbackBaseLink ? UriResolver::resolve($link, $fallbackBaseLink) : $link,
                $this->linkFinder->findFileNames($message)
            )
            : $links;
    }
}
